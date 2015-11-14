<?php

namespace App\Blockchain\Sender;

use App\Blockchain\Sender\TXOChooser;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\ComposedTransactionRepository;
use App\Repositories\LedgerEntryRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\TXORepository;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use Exception;
use Illuminate\Support\Facades\Log;
use Nbobtc\Bitcoind\Bitcoind;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\BitcoinAddressLib\BitcoinKeyUtils;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CounterpartyTransactionComposer\Composer;
use Tokenly\CounterpartyTransactionComposer\Quantity;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\Insight\Client as InsightClient;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XCPDClient\Client as XCPDClient;

class PaymentAddressSender {

    const DEFAULT_FEE                = 0.0001;

    const DEFAULT_REGULAR_DUST_SIZE  = 0.00005430;

    public function __construct(XCPDClient $xcpd_client, InsightClient $insight_client, Bitcoind $bitcoind, CounterpartySender $xcpd_sender, BitcoinPayer $bitcoin_payer, BitcoinAddressGenerator $address_generator, Cache $asset_cache, ComposedTransactionRepository $composed_transaction_repository, TXOChooser $txo_chooser, Composer $transaction_composer, TXORepository $txo_repository, LedgerEntryRepository $ledger_entry_repository, PaymentAddressRepository $payment_address_repository) {
        $this->xcpd_client                     = $xcpd_client;
        $this->insight_client                  = $insight_client;
        $this->bitcoind                        = $bitcoind;
        $this->xcpd_sender                     = $xcpd_sender;
        $this->bitcoin_payer                   = $bitcoin_payer;
        $this->address_generator               = $address_generator;
        $this->asset_cache                     = $asset_cache;
        $this->composed_transaction_repository = $composed_transaction_repository;
        $this->txo_chooser                     = $txo_chooser;
        $this->transaction_composer            = $transaction_composer;
        $this->txo_repository                  = $txo_repository;
        $this->ledger_entry_repository         = $ledger_entry_repository;
        $this->payment_address_repository      = $payment_address_repository;
    }

    // returns [$transaction_id, $float_balance_sent]
    public function sweepBTCByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_fee=null) {
        return $this->sendByRequestID($request_id, $payment_address, $destination, null, 'BTC', $float_fee, null, true);
    }

    // returns [$transaction_id, $float_balance_sent]
    public function sweepBTC(PaymentAddress $payment_address, $destination, $float_fee=null) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sweepBTCByRequestID($request_id, $payment_address, $destination, $float_fee);
    }

    // this cannot be separated into a separate signing and sending step
    //    the sweep must happen after the counterparty sends are done
    // Sends all assets from the default account and then sweeps all BTC UTXOs
    //    To sweep all assets, close all accounts before calling this method
    // returns only the last transaction ID (the BTC sweep)
    public function sweepAllAssets(PaymentAddress $payment_address, $destination, $float_fee=null, $float_btc_dust_size=null) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sweepAllAssetsByRequestID($request_id, $payment_address, $destination, $float_fee, $float_btc_dust_size);
    }

    public function sweepAllAssetsByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_fee=null, $float_btc_dust_size=null) {
        if ($float_fee === null)            { $float_fee            = self::DEFAULT_FEE; }
        if ($float_btc_dust_size === null)  { $float_btc_dust_size  = self::DEFAULT_REGULAR_DUST_SIZE; }

        // combine all balances
        $account = AccountHandler::getAccount($payment_address);
        $balances_by_type = $this->ledger_entry_repository->accountBalancesByAsset($account, null);
        $combined_balances = [];
        foreach($balances_by_type as $type => $balances_for_this_type) {
            if ($type == LedgerEntry::SENDING) { continue; }
            foreach($balances_for_this_type as $asset => $amount) {
                if (!isset($combined_balances[$asset])) { $combined_balances[$asset] = 0; }
                $combined_balances[$asset] += $amount;
            }
        }

        // sort assets by name to be a little deterministic
        ksort($combined_balances);

        // send each asset
        $offset = 0;
        foreach($combined_balances as $asset => $float_quantity) {
            if ($asset == 'BTC') { continue; }
            if ($amount <= 0) { continue; }

            // send this asset with a unique request ID
            $request_id_for_offset = $this->applyOffsetToRequestID($offset, $request_id);
            $this->sendByRequestID($request_id_for_offset, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size);

            ++$offset;
        }

        // sweep the remaining BTC
        return $this->sweepBTCByRequestID($request_id, $payment_address, $destination, $float_fee);
    }

    public function sendByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_btc_dust_size=null, $is_sweep=false) {
        $composed_transaction_model = $this->generateComposedTransactionModel($request_id, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $is_sweep);
        if (!$composed_transaction_model) { return null;}

        $signed_transaction_hex = $composed_transaction_model['transaction'];
        $utxo_identifiers       = $composed_transaction_model['utxos'];

        // push all signed transactions to the bitcoin network
        //   some of these may fail
        $sent_tx_id = null;
        try {
            $sent_tx_id = $this->bitcoind->sendrawtransaction($signed_transaction_hex);
        } catch (Exception $e) {
            Log::debug("bitcoind returned exception: ".$e->getCode());
            if (in_array($e->getCode(), [-25, -26, -27])) {
                // this transaction was rejected, remove it from the composed transaction repository
                //   so it can be created again
                $this->composed_transaction_repository->deleteComposedTransactionsByRequestID($request_id);

                // unspend each spent TXO
                $this->txo_repository->updateByTXOIdentifiers($utxo_identifiers, ['spent' => 0]);

                // delete each new TXO
                $this->txo_repository->deleteByTXID($composed_transaction_model['txid']);

                $error_log_details = compact('request_id', 'txid', 'destination', 'float_quantity', 'asset');
                $error_log_details['errorCode'] = $e->getCode();
                $error_log_details['errorMsg'] = $e->getMessage();
                EventLog::log('composedTransaction.removed', $error_log_details);
            }
            
            // throw the exception
            throw $e;
        }

        return $sent_tx_id;
    }

    public function send(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_btc_dust_size=null, $is_sweep=false) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sendByRequestID($request_id, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $is_sweep);
    }


    
    ////////////////////////////////////////////////////////////////////////
    // Protected

    protected function generateComposedTransactionModel($request_id, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $is_sweep) {
        // check to see if this signed transaction already exists in the database
        $composed_transaction_model = $this->composed_transaction_repository->getComposedTransactionByRequestID($request_id);

        if ($composed_transaction_model === null) {
            // build the signed transactions
            $change_address_collection = null;
            $built_transaction_to_send = $this->buildSignedTransactionToSend($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $float_btc_dust_size, $is_sweep);

            // get the utxo identifiers
            $utxo_identifiers = $this->buildUTXOIdentifiersFromUTXOs($built_transaction_to_send->getInputUtxos());

            // store the signed transactions to the database cache
            $signed_transaction_hex     = $built_transaction_to_send->getTransactionHex();
            $txid                       = $built_transaction_to_send->getTxId();
            $composed_transaction_model = $this->composed_transaction_repository->storeOrFetchComposedTransaction($request_id, $txid, $signed_transaction_hex, $utxo_identifiers);

            // mark each UTXO as spent
            $this->txo_repository->updateByTXOIdentifiers($utxo_identifiers, ['spent' => 1]);

            // create the new UTXOs that belong to any of our addresses
            $account = AccountHandler::getAccount($payment_address);
            $this->clearPaymentAddressInfoCache();
            foreach ($built_transaction_to_send->getOutputUtxos() as $output_utxo) {
                if ($output_utxo['amount'] <= 0) {
                    // don't store OP_RETURN UTXOs with no value
                    continue;
                }

                // create new UTXO
                $utxo_destination_address = AddressFactory::fromOutputScript(ScriptFactory::fromHex($output_utxo['script']))->getAddress();
                list($found_payment_address, $found_account) = $this->loadPaymentAddressInfo($utxo_destination_address);
                if ($found_payment_address) {
    
                    $this->txo_repository->create($found_payment_address, $found_account, [
                        'txid'   => $output_utxo['txid'],
                        'n'      => $output_utxo['n'],
                        'amount' => $output_utxo['amount'],
                        'script' => $output_utxo['script'],

                        'type'   => TXO::UNCONFIRMED,
                        'spent'  => 0,
                        'green'  => 1,
                    ]);
                }
            }
        }

        return $composed_transaction_model;
    }

    protected function clearPaymentAddressInfoCache() { $this->payment_address_info_cache = []; }
    protected function loadPaymentAddressInfo($address) {
        if (!isset($this->payment_address_info_cache)) { $this->payment_address_info_cache = []; }

        if (!isset($this->payment_address_info_cache[$address])) {
            $found_payment_address = $this->payment_address_repository->findByAddress($address)->first();
            if ($found_payment_address) {
                $this->payment_address_info_cache[$address] = [$found_payment_address, AccountHandler::getAccount($found_payment_address)];
            } else {
                $this->payment_address_info_cache[$address] = [null, null];
            }
        }

        return $this->payment_address_info_cache[$address];
    }

    protected function buildSignedTransactionToSend(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $change_address_collection=null, $float_fee=null, $float_btc_dust_size=null, $is_sweep=false) {
        $signed_transaction = null;

        if ($float_fee === null)            { $float_fee            = self::DEFAULT_FEE; }
        if ($float_btc_dust_size === null)  { $float_btc_dust_size  = self::DEFAULT_REGULAR_DUST_SIZE; }
        $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);


        if ($is_sweep) {
            if (strtoupper($asset) != 'BTC') { throw new Exception("Sweep is only allowed for BTC.", 1); }
            
            // compose the BTC transaction
            $chosen_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
            $float_quantity = CurrencyUtil::satoshisToValue($this->sumUTXOs($chosen_txos)) - $float_fee;
            $composed_transaction = $this->transaction_composer->composeSend('BTC', $float_quantity, $destination, $wif_private_key, $chosen_txos, null, $float_fee);
        } else {
            if (strtoupper($asset) == 'BTC') {
                // compose the BTC transaction
                $strategy = ($this->isPrimeSend($payment_address, $destination) ? TXOChooser::STRATEGY_PRIME : TXOChooser::STRATEGY_BALANCED);
                $chosen_txos = $this->txo_chooser->chooseUTXOs($payment_address, $float_quantity, $float_fee, null, $strategy);
                Log::debug("strategy=$strategy Chosen UTXOs: ".$this->debugDumpUTXOs($chosen_txos));
                if (!$chosen_txos) { throw new Exception("Unable to select transaction outputs (UTXOs)", 1); }

                // $signed_transaction = $this->bitcoin_payer->buildSignedTransactionHexToSendBTC($payment_address['address'], $destination, $float_quantity, $wif_private_key, $float_fee);
                if ($change_address_collection === null) { $change_address_collection = $payment_address['address']; }
                $composed_transaction = $this->transaction_composer->composeSend('BTC', $float_quantity, $destination, $wif_private_key, $chosen_txos, $change_address_collection, $float_fee);

            } else {
                // calculate the quantity
                $is_divisible = $this->asset_cache->isDivisible($asset);
                $quantity = new Quantity($float_quantity, $is_divisible);

                // compose the Counterpary and BTC transaction
                $chosen_txos = $this->txo_chooser->chooseUTXOs($payment_address, $float_btc_dust_size, $float_fee);
                Log::debug("Counterparty send Chosen UTXOs: ".$this->debugDumpUTXOs($chosen_txos));

                // build the change
                if ($change_address_collection === null) { $change_address_collection = $payment_address['address']; }

                $composed_transaction = $this->transaction_composer->composeSend($asset, $quantity, $destination, $wif_private_key, $chosen_txos, $change_address_collection, $float_fee, $float_btc_dust_size);


                // debug
                $_debug_parsed_tx = app('\TransactionComposerHelper')->parseCounterpartyTransaction($composed_transaction->getTransactionHex());
                Log::debug("Counterparty send: \$_debug_parsed_tx=".json_encode($_debug_parsed_tx, 192));

            }
        }

        return $composed_transaction;
    }


    protected function buildUTXOIdentifiersFromUTXOs($utxos) {
        $utxo_identifiers = [];
        if ($utxos) {
            foreach ($utxos as $utxo) {
                $utxo_identifiers[] = $utxo['txid'].':'.$utxo['n'];
            }
        }
        return $utxo_identifiers;
    }


    protected function sumUTXOs($utxos) {
        $total = 0;
        foreach($utxos as $utxo) {
            $total += $utxo['amount'];
        }
        return $total;
    }

    protected function applyOffsetToRequestID($offset, $request_id) {
        $suffix = '-'.$offset;

        $request_id_for_offset = $request_id.$suffix;
        $new_strlen = strlen($request_id_for_offset);
        if ($new_strlen <= 36) { return $request_id_for_offset; }

        $request_id_for_offset = substr($request_id_for_offset, 0, 0-strlen($suffix)).$suffix;
        return $request_id_for_offset;

    }

    protected function isPrimeSend(PaymentAddress $payment_address, $destination_or_destinations) {
        $address = $payment_address['address'];

        $all_destinations_are_self = true;
        if (is_array($destination_or_destinations)) {
            foreach($destination_or_destinations as $destination_pair) {
                if ($destination_pair[0] != $payment_address) {
                    $all_destinations_are_self = false;
                    break;
                }
            }
        } else {
            $all_destinations_are_self = ($address == $destination_or_destinations);
        }

        return $all_destinations_are_self;
    }

    // ------------------------------------------------------------------------
    
    
    protected function debugDumpUTXOs($utxos) {
        $out = '';
        $out .= 'total utxos: '.count($utxos)."\n";
        foreach($utxos as $utxo) {
            $out .= '  '.$utxo['txid'].':'.$utxo['n'].' ('.CurrencyUtil::satoshisToValue($utxo['amount']).')'."\n";
        }
        return rtrim($out);
    }



}
