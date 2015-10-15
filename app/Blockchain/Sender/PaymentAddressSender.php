<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use App\Repositories\ComposedTransactionRepository;
use Exception;
use Illuminate\Support\Facades\Log;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\BitcoinAddressLib\BitcoinKeyUtils;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\Insight\Client as InsightClient;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XCPDClient\Client as XCPDClient;

class PaymentAddressSender {

    const DEFAULT_FEE                = 0.0001;

    const DEFAULT_REGULAR_DUST_SIZE  = 0.00005430;
    const DEFAULT_MULTISIG_DUST_SIZE = 0.00007800;

    public function __construct(XCPDClient $xcpd_client, InsightClient $insight_client, CounterpartySender $xcpd_sender, BitcoinPayer $bitcoin_payer, BitcoinAddressGenerator $address_generator, Cache $asset_cache, ComposedTransactionRepository $composed_transaction_repository) {
        $this->xcpd_client                     = $xcpd_client;
        $this->insight_client                  = $insight_client;
        $this->xcpd_sender                     = $xcpd_sender;
        $this->bitcoin_payer                   = $bitcoin_payer;
        $this->address_generator               = $address_generator;
        $this->asset_cache                     = $asset_cache;
        $this->composed_transaction_repository = $composed_transaction_repository;
    }

    // returns [$transaction_id, $float_balance_sent]
    public function sweepBTCByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_fee=null, $float_regular_dust_size=null) {
        return $this->sendByRequestID($request_id, $payment_address, $destination, null, 'BTC', $float_fee, $float_regular_dust_size, true);
    }

    // returns [$transaction_id, $float_balance_sent]
    public function sweepBTC(PaymentAddress $payment_address, $destination, $float_fee=null, $float_regular_dust_size=null) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sweepBTCByRequestID($request_id, $payment_address, $destination, $float_fee, $float_regular_dust_size);
    }

    // this cannot be separated into a separate signing and sending step
    //    the sweep must happen after the counterparty sends are done
    public function sweepAllAssets(PaymentAddress $payment_address, $destination, $float_fee=null, $float_regular_dust_size=null) {
        $balances = $this->xcpd_client->get_balances(['filters' => ['field' => 'address', 'op' => '==', 'value' => $payment_address['address']]]);

        if ($float_fee === null)                { $float_fee                = self::DEFAULT_FEE; }
        if ($float_regular_dust_size === null)  { $float_regular_dust_size  = self::DEFAULT_REGULAR_DUST_SIZE; }

        // send to destination
        $other_xcp_vars = [
            'fee_per_kb'               => CurrencyUtil::valueToSatoshis($float_fee),
            'regular_dust_size'        => CurrencyUtil::valueToSatoshis($float_regular_dust_size),
            'allow_unconfirmed_inputs' => true,
        ];
        $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
        $public_key = BitcoinKeyUtils::publicKeyFromPrivateKey($private_key);
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);

        $last_txid = null;
        foreach($balances as $balance) {
            $asset        = $balance['asset'];
            $quantity_sat = $balance['quantity'];

            // ignore 0 balances
            if ($quantity_sat <= 0) { continue; }

            Log::debug("sending $quantity_sat $asset from {$payment_address['address']} to $destination");
            $txid = $this->xcpd_sender->send($public_key, $wif_private_key, $payment_address['address'], $destination, $quantity_sat, $asset, $other_xcp_vars);
            $last_txid = $txid;
        }

        // wait for the last transaction id to be seen by insight
        // try for 4 seconds
        if ($last_txid) {
            $start = time();
            $attempt = 0;
            while (time() - $start <= 4) {
                ++$attempt;
                try {
                    $insight_tx = $this->insight_client->getTransaction($last_txid);
                } catch (Exception $e) {
                    Log::debug("insight did not find $last_txid on attempt {$attempt}: ".$e->getMessage());
                    $insight_tx = null;
                }
                if ($insight_tx) { break; }

                // sleep and try again (with backoff)
                if ($attempt > 8) {
                    usleep(500000); // 500 ms
                } else if ($attempt > 4) {
                    usleep(250000); // 250 ms
                } else {
                    usleep(100000); // 100 ms
                }
            }
        }

        // Lastly, sweep the BTC
        return $this->send($payment_address, $destination, null, 'BTC', $float_fee, $float_regular_dust_size, true);
    }

    public function sendByRequestID($request_id, PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_regular_dust_size=null, $is_sweep=false) {
        // check to see if this signed transaction already exists in the database
        $signed_transactions = $this->composed_transaction_repository->getComposedTransactionsByRequestID($request_id);

        if ($signed_transactions === null) {
            // build the signed transactions
            $signed_transactions = $this->buildAllSignedTransactions($payment_address, $destination, $float_quantity, $asset, $float_fee, $float_regular_dust_size, $is_sweep);
            $signed_transactions = $this->composed_transaction_repository->storeOrFetchComposedTransactions($request_id, $signed_transactions);
        }

        if (!$signed_transactions) { return null;}

        // push all signed transactions to the bitcoin network
        //   some of these may fail
        $tx_ids = [];
        foreach($signed_transactions as $signed_transaction) {
            try {
                $tx_ids[] = $this->bitcoin_payer->sendSignedTransaction($signed_transaction);
            } catch (Exception $e) {
                if (in_array($e->getCode(), [-25, -26, -27])) {
                    if (count($signed_transactions) == 1) {
                        // this transaction was rejected, remove it from the composed transaction repository
                        //   so it can be created again
                        $this->composed_transaction_repository->deleteComposedTransactionsByRequestID($request_id);

                        $error_log_details = compact('request_id', 'destination', 'float_quantity', 'asset');
                        $error_log_details['errorCode'] = $e->getCode();
                        $error_log_details['errorMsg'] = $e->getMessage();
                        EventLog::log('composedTransaction.removed', $error_log_details);
                    }
                }
                
                // throw the exception
                throw $e;
            }
        }

        if (count($tx_ids) == 1) { return $tx_ids[0]; }
        return $tx_ids;
    }

    public function send(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_regular_dust_size=null, $is_sweep=false) {
        $request_id = Uuid::uuid4()->toString();
        return $this->sendByRequestID($request_id, $payment_address, $destination, $float_quantity, $asset, $float_fee, $float_regular_dust_size, $is_sweep);
    }


    
    ////////////////////////////////////////////////////////////////////////
    // Protected

    protected function buildAllSignedTransactions(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_regular_dust_size=null, $is_sweep=false) {
        $signed_transactions = [];

        if ($float_fee === null)                { $float_fee                = self::DEFAULT_FEE; }
        if ($float_regular_dust_size === null)  { $float_regular_dust_size  = self::DEFAULT_REGULAR_DUST_SIZE; }
        $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
        $public_key = BitcoinKeyUtils::publicKeyFromPrivateKey($private_key);
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);

        if ($is_sweep) {
            if (strtoupper($asset) != 'BTC') { throw new Exception("Sweep is only allowed for BTC.", 1); }
            
            list($signed_transaction, $float_balance) = $this->bitcoin_payer->buildSignedTransactionAndBalanceToSweepBTC($payment_address['address'], $destination, $wif_private_key, $float_fee);
            $signed_transactions[] = $signed_transaction;
        } else {
            if (strtoupper($asset) == 'BTC') {
                $signed_transaction = $this->bitcoin_payer->buildSignedTransactionHexToSendBTC($payment_address['address'], $destination, $float_quantity, $wif_private_key, $float_fee);
                $signed_transactions[] = $signed_transaction;

            } else {
                // if the asset is divisible, then convert to satoshis
                $is_divisible = $this->asset_cache->isDivisible($asset);
                if ($is_divisible) {
                    // divisible - convert to satoshis
                    $quantity_for_xcpd = CurrencyUtil::valueToSatoshis($float_quantity);
                } else {
                    // not divisible - do not use satoshis
                    $quantity_for_xcpd = intval($float_quantity);
                }

                $other_xcp_vars = [
                    'fee_per_kb'               => CurrencyUtil::valueToSatoshis($float_fee),
                    'regular_dust_size'        => CurrencyUtil::valueToSatoshis($float_regular_dust_size),
                    'allow_unconfirmed_inputs' => true,
                ];

                $signed_transaction = $this->xcpd_sender->buildSendTransaction($public_key, $wif_private_key, $payment_address['address'], $destination, $quantity_for_xcpd, $asset, $other_xcp_vars);
                $signed_transactions[] = $signed_transaction;
            }
        }

        return $signed_transactions;
    }

}
