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

    public function create($parsed_tx, $block_seen, $block_confirmed=null) {
        return Transaction::create([
            'txid'            => $parsed_tx['txid'],
            'is_xcp'          => $parsed_tx['isCounterpartyTx'] ? 1 : 0,
            'block_seen'      => $block_seen,
            'block_confirmed' => $block_confirmed === null ? 0 : $block_confirmed,
            'is_mempool'      => $block_confirmed === null ? 1 : 0,
            'parsed_tx'       => $parsed_tx,
        ]);
    }

    public function findByTXID($txid) {
        return Transaction::where('txid', $txid)->first();
    }

    public function updateByTXID($txid, $attributes) {
        return $this->update($this->findByTXID($txid), $attributes);
    }

    public function update(Model $address, $attributes) {
        return $address->update($attributes);
    }

    public function deleteByTXID($txid) {
        if ($address = self::findByTXID($txid)) {
            return self::delete($address);
        }
        return false;
    }

    public function delete(Model $address) {
        return $address->delete();
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
