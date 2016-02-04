<?php

namespace App\Repositories;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use \Exception;

/*
* TransactionRepository
*/
class TransactionRepository
{

    const ADDR_DIRECTION_SOURCE      = 1;
    const ADDR_DIRECTION_DESTINATION = 2;

    public function create($parsed_tx, $block_confirmed_hash, $is_mempool=false, $block_seq=null) {
        $new_transaction = Transaction::create([
            'network'              => $parsed_tx['network'],
            'txid'                 => $parsed_tx['txid'],
            'block_confirmed_hash' => $block_confirmed_hash,
            'is_mempool'           => $is_mempool ? 1 : 0,
            'parsed_tx'            => $parsed_tx,
            'block_seq'            => $block_seq,
        ]);

        $this->createAddressLookupEntries($new_transaction, $parsed_tx);

        return $new_transaction;
    }

    public function findByTXID($txid) {
        return Transaction::where('txid', $txid)->first();
    }

    public function findAllTransactionsConfirmedInBlockHashes($hashes, $columns=['*']) {
        // done allow empty hashes or addresses
        if (!$hashes) { return []; }

        return Transaction::whereIn('block_confirmed_hash', $hashes)->get($columns);
    }

    public function findAllTransactionsConfirmedInBlockHashesInvolvingAddresses($hashes, $addresses) {
        // done allow empty hashes or addresses
        if (!$hashes OR !$addresses) { return []; }

        $query = Transaction::whereIn('block_confirmed_hash', $hashes);

        $query->join('transaction_address_lookup', function ($join) use ($addresses) {
            $join->on('transaction.id', '=', 'transaction_address_lookup.transaction_id')
                ->whereIn('transaction_address_lookup.address', $addresses);
        });

        $query->groupBy('transaction.id');

        return $query->get();
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
        // delete address lookup tables
        if (DB::getDriverName() != 'mysql') {
            DB::table('transaction_address_lookup')->where('transaction_id', $transaction['id'])->delete();
        }

        return $transaction->delete();
    }

    public function deleteOlderThan(Carbon $date) {
        if (DB::getDriverName() != 'mysql') {
            // manual delete for testing
            DB::transaction(function() use ($date) {
                foreach (Transaction::where('updated_at', '<', $date)->get(['id']) as $transaction_model) {
                    DB::table('transaction_address_lookup')->where('transaction_id', $transaction_model['id'])->delete();
                }
            });
        }

        $affected_rows = Transaction::where('updated_at', '<', $date)->delete();
        return;
    }

    public function deleteAll() {
        DB::table('transaction_address_lookup')->truncate();

        return Transaction::truncate();
    }

    public function refreshTransactionLookupEntries(Transaction $transaction) {
        DB::table('transaction_address_lookup')->where('transaction_id', $transaction['id'])->delete();

        $this->createAddressLookupEntries($transaction, $transaction['parsed_tx']);
    }


    // ------------------------------------------------------------------------
    
    protected function createAddressLookupEntries($transaction, $parsed_tx) {
        $entries = [];
        foreach($parsed_tx['sources'] as $source_address) {
            $entries[] = [
                'transaction_id' => $transaction['id'],
                'address'        => $source_address,
                'direction'      => self::ADDR_DIRECTION_SOURCE,
            ];
        }
        foreach($parsed_tx['destinations'] as $destination_address) {
            $entries[] = [
                'transaction_id' => $transaction['id'],
                'address'        => $destination_address,
                'direction'      => self::ADDR_DIRECTION_DESTINATION,
            ];
        }

        DB::table('transaction_address_lookup')->insert($entries);
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
