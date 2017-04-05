<?php

namespace App\Blockchain\Sender;

use App\Blockchain\Sender\CoinSelector;
use App\Blockchain\Sender\Exception\BitcoinDaemonException;
use App\Blockchain\Sender\Exception\CompositionException;
use App\Blockchain\Sender\FeePriority;
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
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Nbobtc\Bitcoind\Bitcoind;
use Ramsey\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\BitcoinAddressLib\BitcoinKeyUtils;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CounterpartyTransactionComposer\ComposedTransaction;
use Tokenly\CounterpartyTransactionComposer\Composer;
use Tokenly\CounterpartyTransactionComposer\Quantity;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XCPDClient\Client as XCPDClient;

class PaymentAddressSender {

    const DEFAULT_FEE                = 0.0001;
    const HIGH_FEE_SATOSHIS          = 1000000; // 0.01;
    const SATOSHI                    = 100000000;

    const DEFAULT_REGULAR_DUST_SIZE = 0.00005430;
    const MINIMUM_DUST_SIZE         = 0.00005000;

    const ASSET_SEND_OP_RETURN_SIZE = 28;

    public function __construct(XCPDClient $xcpd_client, Bitcoind $bitcoind, CounterpartySender $xcpd_sender, BitcoinPayer $bitcoin_payer, BitcoinAddressGenerator $address_generator, Cache $asset_cache, ComposedTransactionRepository $composed_transaction_repository, TXOChooser $txo_chooser, Composer $transaction_composer, TXORepository $txo_repository, LedgerEntryRepository $ledger_entry_repository, PaymentAddressRepository $payment_address_repository, FeePriority $fee_priority) {
        $this->xcpd_client                     = $xcpd_client;
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
        $this->fee_priority                    = $fee_priority;
    }

    // returns $txid
    public function sweepBTCByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_fee=null, $fee_per_byte=null) {
        $built_transaction_to_send = null;
        if ($fee_per_byte) {
            $float_quantity = 0;
            $built_transaction_to_send = $this->buildBestComposedTransactionWithFeePerByte($fee_per_byte, $payment_address, $destination, $float_quantity, 'BTC', $_change_address_collection=null, $_is_sweep=true);
            $float_fee = $built_transaction_to_send->feeFloat();
            Log::debug("sweepBTCByRequestID \$fee_per_byte=".json_encode($fee_per_byte, 192)." \$float_fee=".json_encode($float_fee, 192));
        }
        return $this->sendByRequestID($request_id, $payment_address, $destination, null, 'BTC', $float_fee, $_float_btc_dust_size=null, $_is_sweep=true, $_custom_inputs=false, $built_transaction_to_send);
    }

    // returns $txid
    public function sweepBTC(PaymentAddress $payment_address, $destination, $float_fee=null, $fee_per_byte=null) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sweepBTCByRequestID($request_id, $payment_address, $destination, $float_fee, $fee_per_byte);
    }

    // this cannot be separated into a separate signing and sending step
    //    the sweep must happen after the counterparty sends are done
    // Sends all assets from the default account and then sweeps all BTC UTXOs
    //    To sweep all assets, close all accounts before calling this method
    // returns an numbered array of all transactions with [txid, balances_sent]
    public function sweepAllAssets(PaymentAddress $payment_address, $destination, $float_fee=null, $fee_per_byte=null, $float_btc_dust_size=null) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sweepAllAssetsByRequestID($request_id, $payment_address, $destination, $float_fee, $fee_per_byte, $float_btc_dust_size);
    }

    public function sweepAllAssetsByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_fee=null, $fee_per_byte=null, $float_btc_dust_size=null) {
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

        $sweep_transactions = [];

        // send each asset
        $offset = 0;
        foreach($combined_balances as $asset => $float_quantity) {
            if ($asset == 'BTC') { continue; }
            if ($float_quantity <= 0) { continue; }

            // send this asset with a unique request ID
            $request_id_for_offset = $this->applyOffsetToRequestID($offset, $request_id);
            $built_transaction_to_send = null;
            if ($fee_per_byte) {
                $built_transaction_to_send = $this->buildBestComposedTransactionWithFeePerByte($fee_per_byte, $payment_address, $destination, $float_quantity, $asset, $_change_address_collection=null);
                $float_fee = $built_transaction_to_send->feeFloat();
            }

            $txid = $this->sendByRequestID($request_id_for_offset, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $_is_sweep=false, $_custom_inputs=false, $built_transaction_to_send);
            $sweep_transactions[] = [
                'balances_sent' => [
                    $asset => $float_quantity,
                    'BTC'  => $float_fee + $float_btc_dust_size,
                ],
                'txid'       => $txid,
            ];

            ++$offset;
        }

        // sweep the remaining BTC
        $chosen_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true)->toArray();
        $float_utxo_sum = CurrencyUtil::satoshisToValue($this->sumUTXOs($chosen_txos));
        $txid = $this->sweepBTCByRequestID($request_id, $payment_address, $destination, $float_fee, $fee_per_byte);
        $sweep_transactions[] = [
            'balances_sent' => [
                'BTC'  => $float_utxo_sum,
            ],
            'txid'       => $txid,
        ];

        return $sweep_transactions;
    }

    public function sendByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_btc_dust_size=null, $is_sweep=false, $custom_inputs=false, $built_transaction_to_send=null) {
        $composed_transaction_model = $this->generateSignedTransactionModelAndUpdateUTXORecords($request_id, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $is_sweep, $custom_inputs, $built_transaction_to_send);
        if (!$composed_transaction_model) { return null;}

        $signed_transaction_hex = $composed_transaction_model['transaction'];
        $utxo_identifiers       = $this->buildUTXOIdentifiersFromUTXOs($composed_transaction_model['utxos']);

        $sent_tx_id = $this->pushSignedTransaction($signed_transaction_hex, $composed_transaction_model['txid'], $utxo_identifiers, $request_id);
        return $sent_tx_id;
    }

    public function send(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_btc_dust_size=null, $is_sweep=false, $built_transaction_to_send=null) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sendByRequestID($request_id, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $is_sweep, $_custom_inputs=false, $built_transaction_to_send);
    }

    /**
     * Builds and sends a UTXO consolidation transaction
     * @param  PaymentAddress $payment_address
     * @param  integer        $utxo_count_to_consolidate Number of UTXOs to consolidate
     * @param  mixed          $fee_priority_desc         A fee priority description (medium)
     * @return Tokenly\CounterpartyTransactionComposer\ComposedTransaction The composed transaction object
     */
    public function consolidateUTXOs(PaymentAddress $payment_address, $utxo_count_to_consolidate, $fee_priority_desc='medium') {
        // build the transaction
        $composed_transaction_object = $this->buildTransactionToConsolidateUTXOs($payment_address, $utxo_count_to_consolidate, $fee_priority_desc);

        // update and build the UTXO records
        $this->updateTXORecordsFromComposedTransaction($payment_address, $composed_transaction_object);

        // push the signed transaction
        $sent_tx_id = $this->pushSignedTransactionFromComposedTransactionObject($composed_transaction_object);

        return $composed_transaction_object;
    }


    // calculates the fee in satoshis
    public function buildFeeEstimateInfo(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_btc_dust_size = null, $is_sweep=false) {
        $change_address_collection = null;
        $float_fee = 0;
        $size = $this->estimateSizeWithFee($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $float_btc_dust_size, $is_sweep);
        Log::debug("buildFeeEstimateInfo \$size=".json_encode($size, 192));

        $rates = [
            'low'     => $this->fee_priority->getSatoshisPerByte('low'),
            'lowmed'  => $this->fee_priority->getSatoshisPerByte('lowmed'),
            'med'     => $this->fee_priority->getSatoshisPerByte('medium'),
            'medhigh' => $this->fee_priority->getSatoshisPerByte('medhigh'),
            'high'    => $this->fee_priority->getSatoshisPerByte('high'),
        ];

        return [
            'size' => $size,
            'fees' => [
                'low'     => $size * $rates['low'],
                'lowmed'  => $size * $rates['lowmed'],
                'med'     => $size * $rates['med'],
                'medhigh' => $size * $rates['medhigh'],
                'high'    => $size * $rates['high'],
            ],
        ];
    }

    public function estimateSizeWithFee(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $float_btc_dust_size = null, $is_sweep=false) {
        $change_address_collection = null;
        $composed_transaction = $this->buildComposedTransaction($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $_fee_per_byte=null, $float_btc_dust_size, $is_sweep);
        $size = strlen($composed_transaction->getTransactionHex()) / 2;
        return $size;
    }


    /**
     * Builds a composed transaction with fee estimations
     * @return ComposedTransaction $composed_transaction
     */
    public function composeUnsignedTransaction(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $change_address_collection=null, $float_fee=null, $fee_per_byte=null, $float_btc_dust_size=null, $is_sweep=false) {
        if ($fee_per_byte AND !$is_sweep) {
            $composed_transaction = $this->buildBestComposedTransactionWithFeePerByte($fee_per_byte, $payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee);
        } else {
            $composed_transaction = $this->buildComposedTransaction($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $_fee_per_byte=null, $float_btc_dust_size, $is_sweep);
        }
        return $composed_transaction;
    }

    public function composeUnsignedTransactionByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_btc_dust_size=null, $is_sweep=false) {
        // check to see if this transaction already exists in the database
        $composed_transaction_model = $this->composed_transaction_repository->getComposedTransactionByRequestID($request_id);
        if ($composed_transaction_model) { return $composed_transaction_model; }

        // build a new unsigned transaction
        $change_address_collection = null;
        $fee_per_byte = null;
        if ($fee_per_byte AND !$is_sweep) {
            $built_transaction_to_send = $this->buildBestComposedTransactionWithFeePerByte($fee_per_byte, $payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee);
        } else {
            $built_transaction_to_send = $this->buildComposedTransaction($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $_fee_per_byte=null, $float_btc_dust_size, $is_sweep);
        }

        // get the transaction variables
        $txid            = $built_transaction_to_send->getTxId();
        $transaction_hex = $built_transaction_to_send->getTransactionHex();
        $utxos           = $this->normalizeUTXOsForStorage($built_transaction_to_send->getInputUtxos());
        $is_signed       = $built_transaction_to_send->getSigned();

        $composed_transaction_data = $this->composed_transaction_repository->storeOrFetchComposedTransaction($request_id, $txid, $transaction_hex, $utxos, $is_signed);
        return $composed_transaction_data;
    }

    public function pushSignedComposedTransaction($signed_transaction_hex, $composed_send, $payment_address) {
        // get the utxo identifiers
        $transaction = TransactionFactory::fromHex($signed_transaction_hex);
        $input_utxo_identifiers = $this->buildInputUTXOIdentifiersFromTransaction($transaction);

        // mark each input UTXO as spent
        $this->txo_repository->updateByTXOIdentifiers($input_utxo_identifiers, ['spent' => 1]);

        // create the new UTXOs that belong to any of our addresses
        $output_utxos = $this->buildOutputUTXOsFromTransaction($transaction);
        $this->createNewUTXORecords($payment_address, $output_utxos, false);

        $sent_tx_id = $this->pushSignedTransaction($signed_transaction_hex, $transaction->getTxId()->getHex(), $input_utxo_identifiers, null);
        return $sent_tx_id;

    }

    ////////////////////////////////////////////////////////////////////////
    // Protected

    protected function generateSignedTransactionModelAndUpdateUTXORecords($request_id, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_btc_dust_size, $is_sweep, $custom_inputs=false, $built_transaction_to_send=null) {
        // check to see if this signed transaction already exists in the database
        $composed_transaction_model = $this->composed_transaction_repository->getComposedTransactionByRequestID($request_id);

        if ($composed_transaction_model === null) {
            if ($built_transaction_to_send === null) {
                // build the signed transactions
                $change_address_collection = null;
                $fee_per_byte = null;
                if ($fee_per_byte AND !$is_sweep AND !$custom_inputs) {
                    $built_transaction_to_send = $this->buildBestComposedTransactionWithFeePerByte($fee_per_byte, $payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee);
                } else {
                    $built_transaction_to_send = $this->buildComposedTransaction($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $float_fee, $_fee_per_byte=null, $float_btc_dust_size, $is_sweep, $custom_inputs);
                }
            }


            // store the signed transactions to the database cache
            $signed_transaction_hex     = $built_transaction_to_send->getTransactionHex();
            $txid                       = $built_transaction_to_send->getTxId();
            $is_signed                  = $built_transaction_to_send->getSigned();
            $utxos                      = $this->normalizeUTXOsForStorage($built_transaction_to_send->getInputUtxos());
            Log::debug("\$utxos=".json_encode($utxos, 192));
            $composed_transaction_model = $this->composed_transaction_repository->storeOrFetchComposedTransaction($request_id, $txid, $signed_transaction_hex, $utxos, $is_signed);

            $this->updateTXORecordsFromComposedTransaction($payment_address, $built_transaction_to_send);
        }

        return $composed_transaction_model;
    }

    // updates the status of the spending UTXOs and creates any new UTXOs
    protected function updateTXORecordsFromComposedTransaction(PaymentAddress $payment_address, ComposedTransaction $built_transaction_to_send) {
        // get the utxo identifiers
        $utxo_identifiers = $this->buildUTXOIdentifiersFromUTXOs($built_transaction_to_send->getInputUtxos());
        Log::debug("\updateTXORecordsFromComposedTransaction \$utxo_identifiers=".json_encode($utxo_identifiers, 192));

        // mark each UTXO as spent
        $this->txo_repository->updateByTXOIdentifiers($utxo_identifiers, ['spent' => 1]);

        // create the new UTXOs that belong to any of our addresses
        $this->createNewUTXORecords($payment_address, $built_transaction_to_send->getOutputUtxos());
    }

    // * @param  array  $output_utxos  An array of UTXOs.  Each UTXO should be ['txid' => txid, 'n' => n, 'amount' => amount (in satoshis), 'script' => script hexadecimal string]
    protected function createNewUTXORecords($payment_address, $output_utxos, $green=true) {
        $account = AccountHandler::getAccount($payment_address);
        $this->clearPaymentAddressInfoCache();
        foreach ($output_utxos as $output_utxo) {
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
                    'green'  => $green,
                ]);
            }
        }
    }

    protected function pushSignedTransactionFromComposedTransactionObject(ComposedTransaction $composed_transaction_object) {
        $utxo_identifiers = [];
        $utxo_identifiers = $this->buildUTXOIdentifiersFromUTXOs($composed_transaction_object->getInputUtxos());
        return $this->pushSignedTransaction($composed_transaction_object->getTransactionHex(), $composed_transaction_object->getTxId(), $utxo_identifiers, null);
    }

    protected function pushSignedTransaction($signed_transaction_hex, $txid, $utxo_identifiers, $request_id=null) {
        // push all signed transactions to the bitcoin network
        //   some of these may fail
        $sent_tx_id = null;
        try {
            $sent_tx_id = $this->bitcoind->sendrawtransaction($signed_transaction_hex);
        } catch (Exception $e) {
            EventLog::debug('bitcoind.exception', ['msg' => "bitcoind returned exception: ".$e->getCode(), 'code' => $e->getCode()]);
            if (in_array($e->getCode(), [-25, -26, -27])) {
                if ($request_id) {
                    // this transaction was rejected, remove it from the composed transaction repository
                    //   so it can be created again
                    $this->composed_transaction_repository->deleteComposedTransactionsByRequestID($request_id);
                }

                // unspend each spent TXO
                $this->txo_repository->updateByTXOIdentifiers($utxo_identifiers, ['spent' => 0]);

                // delete each new TXO
                $this->txo_repository->deleteByTXID($txid);

                $error_log_details = compact('request_id', 'txid');
                EventLog::logError('composedTransaction.removed', $e, $error_log_details);
            }
            
            // throw the exception
            throw new BitcoinDaemonException("Bitcoin daemon error while sending raw transaction: ".ltrim($e->getMessage()." ".$e->getCode()), $e->getCode());
        }

        return $sent_tx_id;
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

    protected function buildBestComposedTransactionWithFeePerByte($fee_per_byte, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $change_address_collection, $is_sweep=false) {
        try {
            $composed_transaction = $this->buildComposedTransaction($payment_address, $destination, $float_quantity, $asset, $change_address_collection, $_float_fee=null, $fee_per_byte, $_float_btc_dust_size=null, $is_sweep);
            return $composed_transaction;
        } catch (Exception $e) {
            EventLog::logError('compose.feeestimation.error', $e);
            Log::debug("FAILED to build transaction");
            return null;
        }
    }

    protected function buildComposedTransaction(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $change_address_collection=null, $float_fee=null, $fee_per_byte=null, $float_btc_dust_size=null, $is_sweep=false, $utxo_override=false) {
        Log::debug("buildComposedTransaction \$float_quantity=".json_encode($float_quantity, 192)." \$asset=".json_encode($asset, 192)." \$float_fee=".json_encode($float_fee, 192)." \$fee_per_byte=".json_encode($fee_per_byte, 192)." \$float_btc_dust_size=".json_encode($float_btc_dust_size, 192)." is_sweep=".json_encode($is_sweep, 192));
        $signed_transaction = null;

        if ($float_fee === null)            { $float_fee            = self::DEFAULT_FEE; }
        if ($float_btc_dust_size === null)  { $float_btc_dust_size  = self::DEFAULT_REGULAR_DUST_SIZE; }

        if ($payment_address->isManaged()) {
            $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
            $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);
        } else {
            $wif_private_key = null;
        }

        if ($is_sweep) {
            if (strtoupper($asset) != 'BTC') { throw new Exception("Sweep is only allowed for BTC.", 1); }
            
            // compose the BTC transaction
            $chosen_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true)->toArray();
            $float_utxo_sum = CurrencyUtil::satoshisToValue($this->sumUTXOs($chosen_txos));
            if ($float_utxo_sum < self::MINIMUM_DUST_SIZE) {
                throw new Exception("BTC amount is too small to sweep", 1);
            }
            $float_quantity = $float_utxo_sum - $float_fee;
            if ($float_quantity <= 0) {
                // the fee is too large - try to send it with as much fee as possible
                $float_quantity = self::MINIMUM_DUST_SIZE;
                $float_fee = $float_utxo_sum - $float_quantity;
            }
            $composed_transaction = $this->transaction_composer->composeSend('BTC', $float_quantity, $destination, $wif_private_key, $chosen_txos, null, $float_fee);
        } else {
            if (strtoupper($asset) == 'BTC') {
                // compose the BTC transaction
                if (is_array($utxo_override) AND count($utxo_override) > 0){
                    $chosen_txos = $this->txo_chooser->chooseSpecificUTXOs($payment_address, $utxo_override);
                    $debug_strategy_text = 'custom';
                } else if ($fee_per_byte !== null) {
                    $coin_group = $this->selectCoinGroupForPaymentAddress($payment_address, $fee_per_byte, $float_quantity, $destination, $change_address_collection, false);
                    if (!$coin_group) { throw new CompositionException("Unable to select transaction outputs (UTXOs)", -100); }
                    $chosen_txos = $coin_group['txos'];
                    $debug_strategy_text = 'fee_per_byte';
                    $float_fee = CurrencyUtil::satoshisToValue($coin_group['fee']);
                } else if ($this->isPrimeSend($payment_address, $destination)) {
                    // prime send (with standard fee)
                    $float_prime_size = $this->getPrimeSendSize($destination, $float_quantity);
                    $chosen_txos = $this->txo_chooser->chooseUTXOsForPriming($payment_address, $float_quantity, $float_fee, null, $float_prime_size);
                    $debug_strategy_text = 'prime';
                } else {
                    $chosen_txos = $this->txo_chooser->chooseUTXOs($payment_address, $float_quantity, $float_fee, null, TXOChooser::STRATEGY_BALANCED);
                    $debug_strategy_text = 'balanced';
                }
                // Log::debug("strategy=$debug_strategy_text Chosen UTXOs: ".$this->debugDumpUTXOs($chosen_txos));
                if (!$chosen_txos) { throw new CompositionException("Unable to select transaction outputs (UTXOs)", -100); }

                // $signed_transaction = $this->bitcoin_payer->buildSignedTransactionHexToSendBTC($payment_address['address'], $destination, $float_quantity, $wif_private_key, $float_fee);
                if ($change_address_collection === null) { $change_address_collection = $payment_address['address']; }
                $composed_transaction = $this->transaction_composer->composeSend('BTC', $float_quantity, $destination, $wif_private_key, $chosen_txos, $change_address_collection, $float_fee);

                // parse the composed transaction as a sanity check
                try {
                    $_debug_parsed_tx = app('\TransactionComposerHelper')->parseBTCTransaction($composed_transaction->getTransactionHex(), $chosen_txos);
                    Log::debug("BTC send: \$_debug_parsed_tx=".json_encode($_debug_parsed_tx, 192));
                    // Log::debug("BTC send: \$signed_tx=".$composed_transaction->getTransactionHex());
                } catch (Exception $e) {
                    $qty_desc = $float_quantity;
                    $msg = "Error parsing new send of $qty_desc $asset to $destination";
                    EventLog::logError('compose.reparse.failure', $e, [
                        'msg'         => $msg,
                        'qty'         => $qty_desc,
                        'asset'       => $asset,
                        'destination' => $destination,
                    ]);
                    throw $e;
                }

            } else {
                //counterparty transaction
                
                // calculate the quantity
                $is_divisible = $this->asset_cache->isDivisible($asset);
                $quantity = new Quantity($float_quantity, $is_divisible);

                // compose the Counterpary and BTC transaction
                if (is_array($utxo_override) AND count($utxo_override) > 0){
                    $chosen_txos = $this->txo_chooser->chooseSpecificUTXOs($payment_address, $utxo_override);
                } else if ($fee_per_byte !== null) {
                    $coin_group = $this->selectCoinGroupForPaymentAddress($payment_address, $fee_per_byte, $float_btc_dust_size, $destination, $change_address_collection, true);
                    if (!$coin_group) { throw new CompositionException("Unable to select transaction outputs (UTXOs)", -100); }
                    $chosen_txos = $coin_group['txos'];
                    $float_fee = CurrencyUtil::satoshisToValue($coin_group['fee']);
                } else {
                    $chosen_txos = $this->txo_chooser->chooseUTXOs($payment_address, $float_btc_dust_size, $float_fee);
                }

                Log::info('Txos: '.json_encode($chosen_txos));
                Log::debug("Counterparty send Chosen UTXOs: ".$this->debugDumpUTXOs($chosen_txos));

                // build the change
                if ($change_address_collection === null) { $change_address_collection = $payment_address['address']; }
                $composed_transaction = $this->transaction_composer->composeSend($asset, $quantity, $destination, $wif_private_key, $chosen_txos, $change_address_collection, $float_fee, $float_btc_dust_size);

                // parse the composed transaction as a sanity check
                try {
                    $_debug_parsed_tx = app('\TransactionComposerHelper')->parseCounterpartyTransaction($composed_transaction->getTransactionHex(), $chosen_txos);
                     Log::debug("Counterparty send: \$_debug_parsed_tx=".json_encode($_debug_parsed_tx, 192));
                } catch (Exception $e) {
                    $qty_desc = (($quantity instanceof Quantity) ? $quantity->getRawValue() : $quantity);
                    $msg = "Error parsing new send of $qty_desc $asset to $destination";
                    EventLog::logError('compose.reparse.failure', $e, [
                        'msg'         => $msg,
                        'qty'         => $qty_desc,
                        'asset'       => $asset,
                        'destination' => $destination,
                    ]);
                    throw $e;
                }
            }
        }

        return $composed_transaction;
    }


    protected function buildTransactionToConsolidateUTXOs(PaymentAddress $payment_address, $utxo_count_to_consolidate, $fee_priority_desc) {
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($this->address_generator->privateKey($payment_address['private_key_token']));

        // get the smallest TXOs first
        $chosen_txos = $this->txo_chooser->chooseTXOsByCount($payment_address, $utxo_count_to_consolidate);
        $float_quantity_orig = CurrencyUtil::satoshisToValue($this->sumUTXOs($chosen_txos));

        // use approximate fee
        $float_quantity = $float_quantity_orig;
        $float_fee = self::DEFAULT_FEE;
        if ($float_fee > $float_quantity) { $float_fee = $float_quantity; }
        $float_quantity -= $float_fee;
        $composed_transaction_object = $this->transaction_composer->composeSend('BTC', $float_quantity, $payment_address['address'], $wif_private_key, $chosen_txos, $payment_address['address'], $float_fee);

        // now build again with a better fee
        $size = strlen($composed_transaction_object->getTransactionHex()) / 2;
        $fee_satoshis = $size * $this->fee_priority->getSatoshisPerByte($fee_priority_desc);
        $float_quantity = $float_quantity_orig;
        // prevent high fees (sanity check)
        if ($fee_satoshis > self::HIGH_FEE_SATOSHIS) { throw new Exception("Unexpected high fee for consolidateUTXOs", 1); }
        $float_fee = CurrencyUtil::satoshisToValue($fee_satoshis);
        if ($float_fee > $float_quantity) { $float_fee = $float_quantity; }
        $float_quantity -= $float_fee;
        $composed_transaction_object = $this->transaction_composer->composeSend('BTC', $float_quantity, $payment_address['address'], $wif_private_key, $chosen_txos, $payment_address['address'], $float_fee);

        return $composed_transaction_object;
    }


    protected function buildUTXOIdentifiersFromUTXOs($utxos) {
        $utxo_identifiers = [];
        if ($utxos) {
            foreach ($utxos as $utxo) {
                if (is_array($utxo) OR is_object($utxo)) {
                    $utxo_identifiers[] = $utxo['txid'].':'.$utxo['n'];
                } else {
                    // UTXOs used to be stored as just a string identifier
                    //   handle these legacy cases here
                    $utxo_identifiers[] = $utxo;
                }
            }
        }
        return $utxo_identifiers;
    }

    protected function normalizeUTXOsForStorage($utxos) {
        $normalized_utxos = [];
        if ($utxos) {
            foreach ($utxos as $utxo) {
                $normalized_utxos[] = [
                    'txid'   => $utxo['txid'],
                    'n'      => $utxo['n'],
                    'amount' => $utxo['amount'],
                    'script' => $utxo['script'],
                ];
            }
        }
        return $normalized_utxos;
    }

    protected function buildInputUTXOIdentifiersFromTransaction(TransactionInterface $transaction) {
        $utxo_identifiers = [];

        // inputs
        foreach($transaction->getInputs() as $input) {
            $outpoint = $input->getOutpoint();
            $utxo_identifiers[] = $outpoint->getTxId()->getHex().':'.$outpoint->getVout();
        }

        return $utxo_identifiers;
    }

    protected function buildOutputUTXOsFromTransaction(TransactionInterface $transaction) {
        $utxo_records = [];

        // inputs
        $txid = $transaction->getTxId()->getHex();
        foreach($transaction->getOutputs() as $n => $output) {
            $utxo_records[] = [
                'txid'   => $txid,
                'n'      => $n,
                'amount' => CurrencyUtil::valueToSatoshis($output->getValue()),
                'script' => $output->getScript()->getBuffer()->getHex(),
            ];
        }

        return $utxo_records;
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
                if ($destination_pair[0] != $address) {
                    $all_destinations_are_self = false;
                    break;
                }
            }
        } else {
            $all_destinations_are_self = ($address == $destination_or_destinations);
        }

        return $all_destinations_are_self;
    }

    protected function getPrimeSendSize($destination_or_destinations, $float_quantity) {
        if (is_array($destination_or_destinations)) {
            foreach($destination_or_destinations as $destination_pair) {
                return CurrencyUtil::satoshisToValue($destination_pair[1]);
            }
        } else {
            return $float_quantity;
        }
    }

    // ------------------------------------------------------------------------
    
    protected function selectCoinGroupForPaymentAddress(PaymentAddress $payment_address, $fee_per_byte, $float_quantity, $destination_or_destinations, $change_address_collection, $is_asset_send) {
        $utxos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true)->toArray();

        // build destination outputs
        $outputs = [];
        if (is_array($destination_or_destinations)) {
            foreach($destination_or_destinations as $destination_pair) {
                if (isset($destination_pair[1])) {
                    $float_amount = $destination_pair[1];
                    $outputs[] = ['amount' => CurrencyUtil::valueToSatoshis($float_amount)];
                }
            }
        } else {
            $outputs[] = ['amount' => CurrencyUtil::valueToSatoshis($float_quantity)];
        }

        // build change outputs
        if ($change_address_collection AND is_array($change_address_collection)) {
            foreach($change_address_collection as $change_address_collection_entry) {
                if (isset($change_address_collection_entry[1])) {
                    $float_amount = $change_address_collection_entry[1];
                    $outputs[] = ['amount' => CurrencyUtil::valueToSatoshis($float_amount)];
                }
            }
        }

        // build the selector with the utxos and outputs
        $selector = new CoinSelector($utxos, $outputs, $fee_per_byte);


        // set prime size
        if ($this->isPrimeSend($payment_address, $destination_or_destinations)) {
            $float_prime_size = $this->getPrimeSendSize($destination_or_destinations, $float_quantity);
            $prime_input_size = CurrencyUtil::valueToSatoshis($float_prime_size);
            $selector->setPrimeInputSize($prime_input_size);
        }

        // set OP_RETURN
        if ($is_asset_send) {
            $selector->setOpReturnSize(self::ASSET_SEND_OP_RETURN_SIZE);
        }


        $coin_group = $selector->chooseCoins(); 
        if (!$coin_group) {
            EventLog::debug('coins.selection.failed', [
                'quantity'         => $float_quantity,
                'paymentAddressID' => $payment_address['uuid'],
                'feePerByte'       => $fee_per_byte,
            ]);
            return null;
        }

        EventLog::debug('coins.selected', [
            'in_amount'     => $coin_group['in_amount'],
            'change_amount' => $coin_group['change_amount'],
            'fee'           => $coin_group['fee'],
            'size'          => $coin_group['size'],
        ]);

        return $coin_group;
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
