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

    public function getComposedTransactionsByRequestID($request_id) {
        $loaded_object = DB::connection('untransacted')
            ->table('composed_transactions')
            ->select('transactions')
            ->where(['request_id' => $request_id])
            ->first();
        if (!$loaded_object) { return null; }

        return json_decode($loaded_object->transactions, true);
    }

    public function storeOrFetchComposedTransactions($request_id, Array $transaction_hexes) {

        try {
            $this->storeComposedTransactions($request_id, $transaction_hexes);
        } catch (QueryException $e) {
            if ($e->errorInfo[0] == 23000) {
                try {
                    // fetch the existing one instead of adding a duplicate
                    return $this->getComposedTransactionsByRequestID($request_id);
                } catch (Exception $e) {
                    
                }
                return true;
            }

            throw $e;
        }

        return $transaction_hexes;
    }

    public function storeComposedTransactions($request_id, Array $transaction_hexes) {
        $created_at = Carbon::now();

        $row = DB::connection('untransacted')
            ->table('composed_transactions')
            ->insert([
                'request_id'   => $request_id,
                'transactions' => json_encode($transaction_hexes),
                'created_at'   => $created_at,
            ]);

        return;
    }

}
