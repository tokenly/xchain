<?php

namespace App\Blockchain\Reconciliation;

use App\Models\APICall;
use App\Models\PaymentAddress;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class BlockchainTXOReconciler {

    function __construct(BitcoinPayer $bitcoin_payer, TXORepository $txo_repository) {
        $this->bitcoin_payer  = $bitcoin_payer;
        $this->txo_repository = $txo_repository;
    }

    /**
     * 
     * @param  PaymentAddress $payment_address The payment address to check
     * @return array                           An array like ['any' => true, 'differences' => ['BTC' => ['xchain' => 0, 'daemon' => 0.235], 'FOOCOIN' => ['xchain' => 0, 'daemon' => 11]]]
     */
    public function buildDifferences(PaymentAddress $payment_address) {
        if (!$payment_address) { throw new Exception("Payment address not found", 1); }

        Log::debug("reconciling txos for {$payment_address['address']} ({$payment_address['uuid']})");

        $xchain_utxos_map = [];
        $db_txos = $this->txo_repository->findByPaymentAddress($payment_address, null, true); // unspent only
        foreach($db_txos as $db_txo) {
            if ($db_txo['type'] != TXO::CONFIRMED) { continue; }
            $filtered_utxo = ['txid' => $db_txo['txid'], 'n' => $db_txo['n'], 'script' => $db_txo['script'], 'amount' => $db_txo['amount'],];
            $xchain_utxos_map[$filtered_utxo['txid'].':'.$filtered_utxo['n']] = $filtered_utxo;
        }

        // get daemon utxos
        $daemon_utxos_map = [];
        $all_utxos = $this->bitcoin_payer->getAllUTXOs($payment_address['address']);
        if ($all_utxos) {
            foreach($all_utxos as $utxo) {
                if (!$utxo['confirmed']) { continue; }
                $filtered_utxo = ['txid' => $utxo['txid'], 'n' => $utxo['vout'], 'script' => $utxo['script'], 'amount' => CurrencyUtil::valueToSatoshis($utxo['amount']),];
                $daemon_utxos_map[$utxo['txid'].':'.$utxo['vout']] = $filtered_utxo;
            }
        }

        return $this->buildBalanceDifferencesFromMaps($xchain_utxos_map, $daemon_utxos_map);

    }

    public function reconcileDifferences($differences, PaymentAddress $payment_address) {
        if ($differences['any']) {
            Log::debug("Differences found for {$payment_address['address']} ({$payment_address['uuid']}) ".json_encode($differences, 192));

            $account = AccountHandler::getAccount($payment_address);
            foreach($differences['differences'] as $difference) {
                // delete the xchain txo if it is different in any way
                if (isset($difference['xchain']) AND $difference['xchain']) {
                    $utxo = $difference['xchain'];
                    Log::debug('Removing xchain UTXO: '.json_encode($utxo, 192));

                    // delete
                    $txo_model = $this->txo_repository->findByTXIDAndOffset($utxo['txid'], $utxo['n']);
                    if ($txo_model['payment_address_id'] != $payment_address['id']) {
                        throw new Exception("Mismatched payment address id.  Expected {$payment_address['id']}.  Found {$txo_model['payment_address_id']}", 1);
                    }
                }

                if (isset($difference['daemon'])) {
                    $utxo = $difference['daemon'];
                    Log::debug('Adding daemon UTXO: '.json_encode($utxo, 192));

                    // add the daemon's utxo
                    $this->txo_repository->create($payment_address, $account, [
                        'txid'   => $utxo['txid'],
                        'n'      => $utxo['n'],
                        'script' => $utxo['script'],
                        'amount' => $utxo['amount'],
                    ]);
                }
            }
        } else {
            Log::debug("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");
        }
    }

    // ------------------------------------------------------------------------

    protected function buildBalanceDifferencesFromMaps($xchain_utxos_map, $daemon_utxos_map) {
        $differences = [];
        $any_differences = false;

        foreach($daemon_utxos_map as $daemon_utxo_key => $dum) {
            if (!isset($xchain_utxos_map[$daemon_utxo_key])) {
                $differences[$daemon_utxo_key] = ['xchain' => null, 'daemon' => $daemon_utxos_map[$daemon_utxo_key]];
                $any_differences = true;
            }
        }

        foreach($xchain_utxos_map as $xchain_utxo_key => $dum) {
            if (!isset($daemon_utxos_map[$xchain_utxo_key])) {
                $differences[$xchain_utxo_key] = ['xchain' => $xchain_utxos_map[$xchain_utxo_key], 'daemon' => null];
                $any_differences = true;
            } else {
                if ($this->utxosAreDifferent($daemon_utxos_map[$xchain_utxo_key], $xchain_utxos_map[$xchain_utxo_key])) {
                    $differences[$xchain_utxo_key] = ['xchain' => $xchain_utxos_map[$xchain_utxo_key], 'daemon' => $daemon_utxos_map[$xchain_utxo_key]];
                    $any_differences = true;
                }
            }
        }

        $balance_differences = ['any' => $any_differences, 'differences' => $differences,];
        return $balance_differences;
    }

    protected function utxosAreDifferent($utxo1, $utxo2) {
        return (
            $utxo1['amount'] != $utxo2['amount']
            OR $utxo1['script'] != $utxo2['script']
            OR $utxo1['txid'] != $utxo2['txid']
            OR $utxo1['n'] != $utxo2['n']
        );
    }

}
