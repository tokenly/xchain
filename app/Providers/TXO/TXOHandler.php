<?php

namespace App\Providers\TXO;

use App\Models\PaymentAddress;
use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class TXOHandler {

    const RECEIVED_CONFIRMATIONS_REQUIRED = 2;
    const SEND_CONFIRMATIONS_REQUIRED     = 1;

    const SEND_LOCK_TIMEOUT = 300; // 5 minutes

    use DispatchesCommands;

    function __construct(TXORepository $txo_repository) {
        $this->txo_repository = $txo_repository;
    }


    public function receive(PaymentAddress $payment_address, $parsed_tx, $confirmations) {
        $is_confirmed = ($confirmations >= self::RECEIVED_CONFIRMATIONS_REQUIRED);

        // check for vouts 
        if (!isset($parsed_tx['bitcoinTx']['vout']) OR !$parsed_tx['bitcoinTx']['vout']) {
            EventLog::logError('bitcoinTx.receive.noVouts', ['txid' => $parsed_tx['txid']]);
            return;
        }

        // get the receiving account
        $account = AccountHandler::getAccount($payment_address);
        $bitcoin_address = $payment_address['address'];

        foreach ($parsed_tx['bitcoinTx']['vout'] as $vout) {
            // create the UTXO record
            $is_spendable = false;
            if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['type'])) {
                if ($vout['scriptPubKey']['type'] == 'pubkeyhash') { $is_spendable = true; }
            }

            if ($is_spendable) {
                $vout_bitcoin_address = (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['addresses'])) ? $vout['scriptPubKey']['addresses'][0] : null;
                if ($vout_bitcoin_address AND $vout_bitcoin_address == $bitcoin_address) {
                    $type = ($is_confirmed ? TXO::CONFIRMED : TXO::UNCONFIRMED);
                    $this->txo_repository->updateOrCreate([
                        'txid'   => $parsed_tx['txid'],
                        'n'      => $vout['n'],
                        'type'   => $type,
                        'amount' => CurrencyUtil::valueToSatoshis($vout['value']),
                    ], $payment_address, $account);
                }
            }
        }
    }


    public function send(PaymentAddress $payment_address, $parsed_tx, $confirmations) {
        $is_confirmed = ($confirmations >= self::SEND_CONFIRMATIONS_REQUIRED);

        // check for vins 
        if (!isset($parsed_tx['bitcoinTx']['vin']) OR !$parsed_tx['bitcoinTx']['vin']) {
            EventLog::logError('bitcoinTx.send.noVins', ['txid' => $parsed_tx['txid']]);
            return;
        }

        // get the sending account
        $account = AccountHandler::getAccount($payment_address);

        foreach ($parsed_tx['bitcoinTx']['vin'] as $vin) {
            // create the UTXO record
            $is_spendable = true;

            if ($is_spendable) {
                $spent_txid = (isset($vin['txid'])) ? $vin['txid'] : null;
                $spent_n    = (isset($vin['vout'])) ? $vin['vout'] : null;
                if ($spent_txid AND $spent_n !== null) {
                    $type = ($is_confirmed ? TXO::SENT : TXO::SENDING);
                    $spent = true;

                    // spend the utxo
                    $this->txo_repository->updateOrCreate([
                        'txid'  => $spent_txid,
                        'n'     => $spent_n,
                        'type'  => $type,
                        'spent' => $spent,
                        'amount' => CurrencyUtil::valueToSatoshis($vin['value'])
                    ], $payment_address, $account);
                }
            }
        }
    }

    public function invalidate($parsed_tx) {
        DB::transaction(function() use ($parsed_tx) {
            $txid = $parsed_tx['txid'];

            $existing_txos = $this->txo_repository->findByTXID($txid);
            if (count($existing_txos) == 0) { return; }

            // delete each txo
            foreach($existing_txos as $existing_txo) {
                $this->txo_repository->delete($existing_txo);
            }
        });
    }


    public function extractAllDestinationsFromVouts($parsed_tx) {
        $all_destinations = [];

        foreach ($parsed_tx['bitcoinTx']['vout'] as $vout) {
            $is_spendable = false;
            if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['type'])) {
                if ($vout['scriptPubKey']['type'] == 'pubkeyhash') { $is_spendable = true; }
            }

            if ($is_spendable) {
                $vout_bitcoin_address = (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['addresses'])) ? $vout['scriptPubKey']['addresses'][0] : null;
                if ($vout_bitcoin_address) {
                    $all_destinations[] = $vout_bitcoin_address;
                }
            }
        }

        return $all_destinations;
    }


    ////////////////////////////////////////////////////////////////////////


}
