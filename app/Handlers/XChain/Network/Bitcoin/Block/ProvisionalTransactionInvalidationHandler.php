<?php

namespace App\Handlers\XChain\Network\Bitcoin\Block;


use App\Repositories\ProvisionalTransactionRepository;
use Illuminate\Support\Facades\Log;
use \Exception;

/*
* ProvisionalTransactionInvalidationHandler
*/
class ProvisionalTransactionInvalidationHandler
{
    public function __construct(ProvisionalTransactionRepository $provisional_transaction_repository) {
        $this->provisional_transaction_repository = $provisional_transaction_repository;
    }
    
    public function buildProvisionalTransactionsByUTXO() {
        $provisional_transaction_ids_by_utxos = [];
        foreach ($this->provisional_transaction_repository->findAll() as $provisional_transaction) {
            $transaction = $provisional_transaction->transaction;
            $provisional_transaction_id = $provisional_transaction['id'];

            foreach ($this->buildUTXOKeys($transaction['parsed_tx']) as $utxo_key) {
                if (!isset($provisional_transaction_ids_by_utxos[$utxo_key])) { $provisional_transaction_ids_by_utxos[$utxo_key] = []; }
                $provisional_transaction_ids_by_utxos[$utxo_key][] = $provisional_transaction_id;
            }
        }

        return $provisional_transaction_ids_by_utxos;
    }

    public function findInvalidatedTransactions($confirmed_parsed_tx, $provisional_transaction_ids_by_utxos) {
        $invalidated_provisional_transactions_by_id = [];

        foreach ($this->buildUTXOKeys($confirmed_parsed_tx) as $utxo_key) {
            if (isset($provisional_transaction_ids_by_utxos[$utxo_key])) {
                $unique_provisional_transaction_ids = array_unique($provisional_transaction_ids_by_utxos[$utxo_key]);
                sort($unique_provisional_transaction_ids, SORT_NUMERIC);
                // Log::debug("findInvalidatedTransactions \$utxo_key=$utxo_key provisional_transaction_ids: ".json_encode($unique_provisional_transaction_ids, 192));
                foreach ($unique_provisional_transaction_ids as $provisional_transaction_id) {
                    if (isset($invalidated_provisional_transactions_by_id[$provisional_transaction_id])) { continue; }
                    $provisional_transaction = $this->provisional_transaction_repository->findByID($provisional_transaction_id);
                    if ($provisional_transaction) {
                        // Log::debug("\$provisional_transaction=".json_encode($provisional_transaction, 192));
                        // Log::debug("\$provisional_transaction['txid']={$provisional_transaction['txid']}  \$confirmed_parsed_tx['txid']={$confirmed_parsed_tx['txid']}");
                        $invalidated_provisional_transactions_by_id[$provisional_transaction_id] = $provisional_transaction;
                    }
                }
            }
        }

        return $invalidated_provisional_transactions_by_id;

    }

    protected function buildUTXOKeys($parsed_tx) {
        $utxo_keys = [];

        $vins = $parsed_tx['bitcoinTx']['vin'];
        if ($vins) {
            foreach($vins as $vin) {
                if (isset($vin['txid'])) {
                    $key = $vin['txid'].'-'.$vin['vout'];
                } else {
                    $key = md5(json_encode($vin));
                }
                $utxo_keys[] = $key;
            }
        }

        return $utxo_keys;
    }
 

}