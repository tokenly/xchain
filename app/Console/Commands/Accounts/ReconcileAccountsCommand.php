<?php

namespace App\Console\Commands\Accounts;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Util\ArrayToTextTable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Log;
use LinusU\Bitcoin\AddressValidator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReconcileAccountsCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:reconcile-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconciles account balances';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['payment-address-uuid', InputArgument::OPTIONAL, 'Payment Address UUID'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            // ['inactive', 'i', InputOption::VALUE_NONE, 'Include inactive accounts'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $ledger               = app('App\Repositories\LedgerEntryRepository');
        $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
        $account_repository   = app('App\Repositories\AccountRepository');
        $user_repository      = app('App\Repositories\UserRepository');
        $xcpd_client          = app('Tokenly\XCPDClient\Client');
        $bitcoin_payer        = app('Tokenly\BitcoinPayer\BitcoinPayer');
        $asset_info_cache     = app('Tokenly\CounterpartyAssetInfoCache\Cache');

        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');

        if ($payment_address_uuid) {
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }
            $payment_addresses = [$payment_address];
        } else {
            $payment_addresses = $payment_address_repo->findAll();
        }

        foreach($payment_addresses as $payment_address) {
            Log::debug("reconciling accounts for {$payment_address['address']} ({$payment_address['uuid']})");


            // get XCP balances
            $balances = $xcpd_client->get_balances(['filters' => ['field' => 'address', 'op' => '==', 'value' => $payment_address['address']]]);

            // and get BTC balance too
            $btc_float_balance = $bitcoin_payer->getBalance($payment_address['address']);
            $balances = array_merge([['asset' => 'BTC', 'quantity' => $btc_float_balance]], $balances);


            // get xchain balances
            $raw_xchain_balances_by_type = $ledger->combinedAccountBalancesByAsset($payment_address, null);
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
                    $is_divisible = $asset_info_cache->isDivisible($asset_name);
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
            $differences = $this->buildDifferences($combined_xchain_balances, $daemon_balances);
            if ($differences['any']) {
                $this->comment("Differences found for {$payment_address['address']} ({$payment_address['uuid']})");
                $this->line(json_encode($differences, 192));
                $this->line('');
            } else {
                Log::debug("no differences for {$payment_address['address']} ({$payment_address['uuid']})");

                if ($payment_address_uuid) {
                    // only showing one address
                    $this->comment("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");

                }
            }

        }

        $this->info('done');
    }

    protected function buildDifferences($xchain_map, $daemon_map) {
        // $items_to_add = [];
        // $items_to_delete = [];
        $differences = [];
        $any_differences = false;

        foreach($daemon_map as $daemon_map_key => $dum) {
            if (!isset($xchain_map[$daemon_map_key]) AND $daemon_map[$daemon_map_key] != 0) {
                // $items_to_add[] = [$daemon_map_key => $daemon_map[$daemon_map_key]];
                $differences[$daemon_map_key] = ['xchain' => '[NULL]', 'daemon' => $daemon_map[$daemon_map_key]];
                $any_differences = true;
            }
        }

        foreach($xchain_map as $xchain_map_key => $dum) {
            if (!isset($daemon_map[$xchain_map_key])) {
                // $items_to_delete[] = $xchain_map_key;
                $differences[$xchain_map_key] = ['xchain' => $xchain_map[$xchain_map_key], 'daemon' => '[NULL]'];
                $any_differences = true;
            } else {
                if (CurrencyUtil::valueToFormattedString($daemon_map[$xchain_map_key]) != CurrencyUtil::valueToFormattedString($xchain_map[$xchain_map_key])) {
                    $differences[$xchain_map_key] = ['xchain' => $xchain_map[$xchain_map_key], 'daemon' => $daemon_map[$xchain_map_key]];
                    $any_differences = true;
                }
            }
        }
        
        // return ['any' => $any_differences, 'add' => $items_to_add, 'updates' => $differences, 'delete' => $items_to_delete];
        return ['any' => $any_differences, 'differences' => $differences,];
    }


}
