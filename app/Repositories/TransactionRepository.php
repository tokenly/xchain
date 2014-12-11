<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* TransactionRepository
*/
class TransactionRepository
{

    public function create($parsed_tx) {
        return Transaction::create([
            'txid'                 => $parsed_tx['txid'],
            'is_xcp'               => $parsed_tx['isCounterpartyTx'] ? 1 : 0,
            'block_confirmed_hash' => isset($parsed_tx['bitcoinTx']['blockhash']) ? $parsed_tx['bitcoinTx']['blockhash'] : null,
            'is_mempool'           => isset($parsed_tx['bitcoinTx']['blockhash']) ? 0 : 1,
            'parsed_tx'            => $parsed_tx,
        ]);
    }

    public function findByTXID($txid) {
        return Transaction::where('txid', $txid)->first();
    }

    public function findAllTransactionsConfirmedInBlockHashes($hashes, $columns=['*']) {
        return Transaction::whereIn('block_confirmed_hash', $hashes)->get($columns);
    }

    public function updateByTXID($txid, $attributes) {
        return $this->update($this->findByTXID($txid), $attributes);
    }

    public function update(Model $transaction, $attributes) {
        return $transaction->update($attributes);
    }

    public function deleteByTXID($txid) {
        if ($transaction = self::findByTXID($txid)) {
            return self::delete($transaction);
        }
        return false;
    }

    public function delete(Model $transaction) {
        return $transaction->delete();
    }


}

// CREATE TABLE IF NOT EXISTS `blockchaintransaction` (
//     `id`            int(11) unsigned NOT NULL AUTO_INCREMENT,
//     `blockId`       int(20) unsigned NOT NULL DEFAULT 0,
//     `destination`   varbinary(34) NOT NULL,
//     `tx_hash`       varbinary(64) NOT NULL DEFAULT '',
//     `isMempool`     int(1) NOT NULL DEFAULT '0',
//     `isNative`      int(1) NOT NULL DEFAULT '0',
//     `document`      LONGTEXT NOT NULL DEFAULT '',
//     PRIMARY KEY (`id`),
//     KEY `blockId` (`blockId`),
//     KEY `destination` (`destination`),
//     KEY `isMempool_isNative` (`isMempool`,`isNative`),
//     UNIQUE KEY `tx_hash_destination_isNative` (`tx_hash`,`destination`,`isNative`)
// ) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8;
