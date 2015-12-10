<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use App\Repositories\TransactionRepository;
use BitWasp\Bitcoin\Address\ScriptHashAddress;
use BitWasp\Bitcoin\Key\PublicKeyFactory;
use BitWasp\Bitcoin\Script\Classifier\InputClassifier;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use Exception;
use Illuminate\Support\Facades\Log;
use Nbobtc\Bitcoind\Bitcoind;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

/**
 * Builds data for a bitcoin transaction
 *   adds n, addr, value, valueSat to each vin
 *   adds valueIn, valueInSat, valueOut, valueOutSat, fees, feesSat to the transaction data
 */
class EnhancedBitcoindTransactionBuilder {

    public function __construct(TransactionRepository $transaction_repository, Bitcoind $bitcoind) {
        $this->transaction_repository = $transaction_repository;
        $this->bitcoind               = $bitcoind;
    }


    public function buildTransactionData($txid) {
        $enhanced_bitcoind_transaction = $this->loadTransactionFromBitcoind($txid);

        // enhance vins
        $enhanced_vins = [];
        foreach ($enhanced_bitcoind_transaction['vin'] as $n => $vin) {
            $enhanced_vins[] = $this->enhanceVin($vin, $n);
        }
        $enhanced_bitcoind_transaction['vin'] = $enhanced_vins;


        // values in, out and fees
        $enhanced_bitcoind_transaction['valueIn'] = 0.0;
        $enhanced_bitcoind_transaction['valueInSat'] = $this->sumValuesIn($enhanced_vins);
        $enhanced_bitcoind_transaction['valueIn'] = CurrencyUtil::satoshisToValue($enhanced_bitcoind_transaction['valueInSat']);

        $enhanced_bitcoind_transaction['valueOut'] = $this->sumValuesOut($enhanced_bitcoind_transaction['vout']);
        $enhanced_bitcoind_transaction['valueOutSat'] = CurrencyUtil::valueToSatoshis($enhanced_bitcoind_transaction['valueOut']);

        $enhanced_bitcoind_transaction['fees'] = 0.0;
        $enhanced_bitcoind_transaction['feesSat'] = $enhanced_bitcoind_transaction['valueInSat'] - $enhanced_bitcoind_transaction['valueOutSat'];
        $enhanced_bitcoind_transaction['fees'] = CurrencyUtil::satoshisToValue($enhanced_bitcoind_transaction['feesSat']);


        return $enhanced_bitcoind_transaction;
    }

    protected function loadTransactionFromBitcoind($txid) {
        $result = app('Nbobtc\Bitcoind\Bitcoind')->getrawtransaction($txid, true);

        // to array
        $result = json_decode(json_encode($result), true);

        return $result;
    }

    protected function enhanceVin($vin, $n) {
        if (!isset($vin['scriptSig']) OR !isset($vin['scriptSig']['hex'])) { return $vin; }

        // add n
        $vin['n'] = $n;

        // extract the address
        $address = $this->addressFromScriptHex($vin['scriptSig']['hex']);
        $vin['addr'] = $address;

        // build value
        $value = $this->buildValueFromTXO($vin['txid'], $vin['vout']);
        $vin['value'] = $value;
        $vin['valueSat'] = CurrencyUtil::valueToSatoshis($value);

        return $vin;

    }

    protected function addressFromScriptHex($script_hex) {
        $address = null;

        try {
            $script = ScriptFactory::fromHex($script_hex);
            $classifier = new InputClassifier($script);
            if ($classifier->isPayToPublicKeyHash()) {

                $decoded = $script->getScriptParser()->decode();
                $public_key = PublicKeyFactory::fromHex($decoded[1]->getData());
                $address = $public_key->getAddress()->getAddress();

            } else if ($classifier->isPayToScriptHash()) {

                $decoded = $script->getScriptParser()->decode();
                $hex_buffer = $decoded[count($decoded)-1]->getData();
                $sh_address = new ScriptHashAddress(ScriptFactory::fromHex($hex_buffer)->getScriptHash());
                $address = $sh_address->getAddress();

            } else {
                // unknown script type
                Log::debug("Unable to classify script ".substr($hex, 0, 20)."...");
            }
        } catch (Exception $e) {
            Log::error("failed to get address from script. ".$e->getMessage());
        }

        return $address;
    }

    protected function buildValueFromTXO($txid, $vout) {
        $transaction_model = $this->transaction_repository->findByTXID($txid);
        if ($transaction_model) {
            return $this->buildValueFromTransactionModel($transaction_model, $vout);
        }

        // load from bitcoind
        $bitcoind_transaction = $this->loadTransactionFromBitcoind($txid);
        return $this->buildValueFromBitcoindTransaction($bitcoind_transaction, $vout);
    }

    protected function buildValueFromTransactionModel($transaction_model, $vout) {
        return floatval($transaction_model['parsed_tx']['bitcoinTx']['vout'][$vout]['value']);
    }

    protected function buildValueFromBitcoindTransaction($bitcoind_transaction, $vout) {
        $vout = $bitcoind_transaction['vout'][$vout];
        return $vout['value'];
    }

    protected function sumValuesIn($vins) {
        $sum_sat = 0;
        foreach($vins as $vin) {
            // Unimplemented: ignoring coinbase transactions for now
            if (isset($vin['coinbase']) AND $vin['coinbase']) { continue; }

            $sum_sat += $vin['valueSat'];
        }
        return $sum_sat;
    }

    protected function sumValuesOut($vouts) {
        $sum_float = 0;
        foreach($vouts as $vout) {
            $sum_float += $vout['value'];
        }
        return $sum_float;
    }

}
