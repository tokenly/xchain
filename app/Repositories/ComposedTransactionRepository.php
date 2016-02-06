<?php

namespace App\Repositories;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
* ComposedTransactionRepository
*/
class ComposedTransactionRepository
{

    public function getComposedTransactionByRequestID($request_id) {
        $loaded_object = DB::connection('untransacted')
            ->table('composed_transactions')
            ->where(['request_id' => $request_id])
            ->first();
        if (!$loaded_object) { return null; }

        return $this->unserializeTransactionModelFromDatabase([
            'txid'        => $loaded_object->txid,
            'transaction' => $loaded_object->transaction,
            'utxos'       => $loaded_object->utxos,
            'request_id'  => $loaded_object->request_id,
            'signed'      => $loaded_object->signed,
            'created_at'  => $loaded_object->created_at,
        ]);
    }

    public function storeOrFetchComposedTransaction($request_id, $txid, $transaction_hex, Array $utxos, $signed) {

        try {
            return $this->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos, $signed);
        } catch (QueryException $e) {
            if ($e->errorInfo[0] == 23000) {
                // fetch the existing one instead of adding a duplicate
                return $this->getComposedTransactionByRequestID($request_id);
            }

            throw $e;
        }

    }

    public function storeComposedTransaction($request_id, $txid, $transaction_hex, Array $utxos, $signed) {
        $created_at = Carbon::now();

        $create_vars = [
            'txid'        => $txid,
            'request_id'  => $request_id,
            'transaction' => $transaction_hex,
            'utxos'       => json_encode($utxos),
            'signed'      => $signed,
            'created_at'  => $created_at,
        ];

        $row = DB::connection('untransacted')
            ->table('composed_transactions')
            ->insert($create_vars);

        return $this->unserializeTransactionModelFromDatabase($create_vars);
    }

    public function deleteComposedTransactionsByRequestID($request_id) {
        $result = DB::connection('untransacted')
            ->table('composed_transactions')
            ->where(['request_id' => $request_id])
            ->delete();

        return $result;
    }

    protected function unserializeTransactionModelFromDatabase($raw_model) {
        return [
            'txid'        => $raw_model['txid'],
            'transaction' => $raw_model['transaction'],
            'utxos'       => json_decode($raw_model['utxos'], true),
            'request_id'  => $raw_model['request_id'],
            'signed'      => $raw_model['signed'],
            'created_at'  => Carbon::parse($raw_model['created_at']),
        ];
    }
}
