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

        return [
            'txid'         => $loaded_object->txid,
            'transaction'  => $loaded_object->transaction,
            'utxos'        => json_decode($loaded_object->utxos, true),
            'request_id'   => $loaded_object->request_id,
        ];
    }

    public function storeOrFetchComposedTransaction($request_id, $txid, $transaction_hex, Array $utxos) {

        try {
            $this->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos);
        } catch (QueryException $e) {
            if ($e->errorInfo[0] == 23000) {
                try {
                    // fetch the existing one instead of adding a duplicate
                    return $this->getComposedTransactionByRequestID($request_id);
                } catch (Exception $e) {
                    
                }
                return true;
            }

            throw $e;
        }

        return [
            'txid'         => $txid,
            'transaction'  => $transaction_hex,
            'utxos'        => $utxos,
            'request_id'   => $request_id,
        ];
    }

    public function storeComposedTransaction($request_id, $txid, $transaction_hex, Array $utxos) {
        $created_at = Carbon::now();

        $row = DB::connection('untransacted')
            ->table('composed_transactions')
            ->insert([
                'txid'         => $txid,
                'request_id'   => $request_id,
                'transaction'  => $transaction_hex,
                'utxos'        => json_encode($utxos),
                'created_at'   => $created_at,
            ]);

        return;
    }

    public function deleteComposedTransactionsByRequestID($request_id) {
        $result = DB::connection('untransacted')
            ->table('composed_transactions')
            ->where(['request_id' => $request_id])
            ->delete();

        return $result;
    }

}
