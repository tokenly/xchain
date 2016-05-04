<?php

namespace App\Blockchain\Reconciliation;

use App\Models\APICall;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\LedgerEntryRepository;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartyAssetInfoCache\Cache as AssetInfoCache;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XCPDClient\Client as XChainClient;

class BlockchainBalanceReconciler {

    function __construct(LedgerEntryRepository $ledger, XChainClient $xcpd_client, BitcoinPayer $bitcoin_payer, AssetInfoCache $asset_info_cache) {
        $this->ledger           = $ledger;
        $this->xcpd_client      = $xcpd_client;
        $this->bitcoin_payer    = $bitcoin_payer;
        $this->asset_info_cache = $asset_info_cache;
    }

    /**
     * 
     * @param  PaymentAddress $payment_address The payment address to check
     * @return array                           An array like ['any' => true, 'differences' => ['BTC' => ['xchain' => 0, 'daemon' => 0.235], 'FOOCOIN' => ['xchain' => 0, 'daemon' => 11]]]
     */
    public function buildDifferences(PaymentAddress $payment_address) {
        if (!$payment_address) { throw new Exception("Payment address not found", 1); }

        Log::debug("reconciling accounts for {$payment_address['address']} ({$payment_address['uuid']})");

        // get XCP balances
        $balances = $this->xcpd_client->get_balances(['filters' => ['field' => 'address', 'op' => '==', 'value' => $payment_address['address']]]);

        // and get the BTC balance too
        $btc_float_balance = $this->bitcoin_payer->getBalance($payment_address['address']);
        $balances = array_merge([['asset' => 'BTC', 'quantity' => $btc_float_balance]], $balances);

        // get confirmed xchain balances
        $raw_xchain_balances_by_type = $this->ledger->combinedAccountBalancesByAsset($payment_address, null);
        $combined_xchain_balances = [];
        foreach($raw_xchain_balances_by_type as $type_string => $xchain_balances) {
            foreach($xchain_balances as $asset => $quantity) {
                if ($type_string == 'sending' AND $asset == 'BTC') { continue; }
                if ($type_string == 'unconfirmed' AND $asset != 'BTC') { continue; }
                if (!isset($combined_xchain_balances[$asset])) { $combined_xchain_balances[$asset] = 0.0; }
                $combined_xchain_balances[$asset] += $quantity;
            }
        }

        // get daemon balances
        $daemon_balances = [];
        foreach($balances as $balance) {
            $asset_name = $balance['asset'];

            if ($asset_name == 'BTC') {
                // BTC quantity is a float
                $quantity_float = floatval($balance['quantity']);
            } else {
                // determine quantity based on asset info
                $is_divisible = $this->asset_info_cache->isDivisible($asset_name);
                if ($is_divisible) {
                    $quantity_float = CurrencyUtil::satoshisToValue($balance['quantity']);
                } else {
                    // non-divisible assets don't use satoshis
                    $quantity_float = floatval($balance['quantity']);
                }
            }

            $daemon_balances[$asset_name] = $quantity_float;
        }

        // compare
        $balance_differences = $this->buildBalanceDifferencesFromMaps($combined_xchain_balances, $daemon_balances);

        return $balance_differences;
    }

    public function reconcileDifferences($balance_differences, PaymentAddress $payment_address, APICall $api_call=null) {
        if ($balance_differences['any']) {
            // sync daemon balances
            foreach($balance_differences['differences'] as $asset => $qty_by_type) {
                $xchain_quantity = $qty_by_type['xchain'];
                $daemon_quantity = $qty_by_type['daemon'];

                $default_account = AccountHandler::getAccount($payment_address, 'default');
                if ($xchain_quantity > $daemon_quantity) {
                    // debit
                    $quantity = $xchain_quantity - $daemon_quantity;
                    $msg = "Debiting $quantity $asset from account {$default_account['name']}";
                    Log::debug("$msg");
                    $this->ledger->addDebit($quantity, $asset, $default_account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, null, $api_call);

                } else {
                    // credit
                    $quantity = $daemon_quantity - $xchain_quantity;
                    $msg = "Crediting $quantity $asset to account {$default_account['name']}";
                    Log::debug("$msg");
                    $this->ledger->addCredit($quantity, $asset, $default_account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, null, $api_call);

                }
            }

        } else {
            Log::debug("no differences for {$payment_address['address']} ({$payment_address['uuid']})");
        }
    }

    // ------------------------------------------------------------------------

    protected function buildBalanceDifferencesFromMaps($xchain_map, $daemon_map) {
        // $items_to_add = [];
        // $items_to_delete = [];
        $deltas = [];
        $balances_found = [];
        $any_differences_exist = false;

        foreach($daemon_map as $daemon_map_key => $dum) {
            if (!isset($xchain_map[$daemon_map_key]) AND $daemon_map[$daemon_map_key] != 0) {
                // $items_to_add[] = [$daemon_map_key => $daemon_map[$daemon_map_key]];
                $deltas[$daemon_map_key] = ['xchain' => 0, 'daemon' => $daemon_map[$daemon_map_key]];
                $balances_found[$daemon_map_key] = ['xchain' => false, 'daemon' => true];
                $any_differences_exist = true;
            }
        }

        foreach($xchain_map as $xchain_map_key => $dum) {
            if (!isset($daemon_map[$xchain_map_key])) {
                // $items_to_delete[] = $xchain_map_key;
                $deltas[$xchain_map_key] = ['xchain' => $xchain_map[$xchain_map_key], 'daemon' => '[NULL]'];
                $balances_found[$daemon_map_key] = ['xchain' => true, 'daemon' => false];
                $any_differences_exist = true;
            } else {
                $balances_found[$daemon_map_key] = ['xchain' => true, 'daemon' => true];
                if (CurrencyUtil::valueToFormattedString($daemon_map[$xchain_map_key]) != CurrencyUtil::valueToFormattedString($xchain_map[$xchain_map_key])) {
                    $deltas[$xchain_map_key] = ['xchain' => $xchain_map[$xchain_map_key], 'daemon' => $daemon_map[$xchain_map_key]];
                    $any_differences_exist = true;
                }
            }
        }
        return ['any' => $any_differences_exist, 'differences' => $deltas,];
    }

}
