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
        $provisional_transactions_by_utxos = [];
        foreach ($this->provisional_transaction_repository->findAll() as $provisional_transaction) {
            $transaction = $provisional_transaction->transaction;
            $provisional_transaction_id = $provisional_transaction['id'];

            foreach ($this->buildUTXOKeys($transaction['parsed_tx']) as $utxo_key) {
                if (!isset($provisional_transactions_by_utxos[$utxo_key])) { $provisional_transactions_by_utxos[$utxo_key] = []; }
                $provisional_transactions_by_utxos[$utxo_key][] = $provisional_transaction_id;
            }
        }

        return $provisional_transactions_by_utxos;
    }

    public function findInvalidatedTransactions($confirmed_parsed_tx, $provisional_transactions_by_utxos) {
        $invalidated_provisional_transactions = [];

        foreach ($this->buildUTXOKeys($confirmed_parsed_tx) as $utxo_key) {
            if (isset($provisional_transactions_by_utxos[$utxo_key])) {
                foreach ($provisional_transactions_by_utxos[$utxo_key] as $provisional_transaction_id) {
                    $provisional_transaction = $this->provisional_transaction_repository->findByID($provisional_transaction_id);
                    if ($provisional_transaction) {
                        $invalidated_provisional_transactions[] = $provisional_transaction;
                    }
                }
            }
        }

        return $invalidated_provisional_transactions;

    }

    protected function buildUTXOKeys($parsed_tx) {
        $utxo_keys = [];

        $vins = $parsed_tx['bitcoinTx']['vin'];
        if ($vins) {
            foreach($vins as $vin) {
                if (isset($vin['txid'])) {
                    $key = $vin['txid'].'-'.$vin['n'];
                } else {
                    $key = md5(json_encode($vin));
                }
                $utxo_keys[] = $key;
            }
        }

        return $utxo_keys;
    }
 

}