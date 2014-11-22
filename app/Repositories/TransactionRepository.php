<?php

namespace App\Repositories;

use App\Models\Transaction;
use \Exception;

/*
* TransactionRepository
*/
class TransactionRepository
{

    public function create($txid, $is_xcp, $block_seen, $block_confirmed=null) {
        return Transaction::create([
            'txid'            => $txid,
            'is_xcp'          => $is_xcp ? 1 : 0,
            'block_seen'      => $block_seen,
            'block_confirmed' => $block_confirmed === null ? 0 : $block_confirmed,
            'is_mempool'          => $block_confirmed === null ? 1 : 0,
        ]);
    }

    public function findByTXID($txid) {
        return Transaction::where('txid', $txid)->first();
    }

}
