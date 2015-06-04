<?php

use App\Commands\PruneTransactions;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class PruneTransactionsTest extends TestCase {

    protected $useDatabase = true;

    use DispatchesCommands;

    public function testPruneAllTransactions() {
        $tx_helper = app('SampleTransactionsHelper');
        $created_txs = [];
        for ($i=0; $i < 5; $i++) { 
            $created_txs[] = $tx_helper->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => str_repeat('0', 63).($i+1)]);
        }

        // prune all
        $this->dispatch(new PruneTransactions(0));

        // check that all transactions were erased
        $tx_repository = app('App\Repositories\TransactionRepository');
        foreach($created_txs as $created_tx) {
            $loaded_tx = $tx_repository->findByTXID($created_tx['txid']);
            PHPUnit::assertNull($loaded_tx);
        }
    }

    public function testPruneOldTransactions() {
        $tx_helper = app('SampleTransactionsHelper');
        $created_txs = [];
        for ($i=0; $i < 5; $i++) { 
            $created_txs[] = $tx_helper->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => str_repeat('0', 63).($i+1)]);
        }

        $tx_repository = app('App\Repositories\TransactionRepository');
        $created_txs[0]->timestamps = false;
        $tx_repository->update($created_txs[0], ['updated_at' => time() - 60], ['timestamps' => false]);
        $created_txs[1]->timestamps = false;
        $tx_repository->update($created_txs[1], ['updated_at' => time() - 59], ['timestamps' => false]);
        $created_txs[2]->timestamps = false;
        $tx_repository->update($created_txs[2], ['updated_at' => time() - 5],  ['timestamps' => false]);

        // prune all
        $this->dispatch(new PruneTransactions(50));

        // check that all transactions were erased
        $tx_repository = app('App\Repositories\TransactionRepository');
        foreach($created_txs as $offset => $created_tx) {
            $loaded_tx = $tx_repository->findByTXID($created_tx['txid']);
            if ($offset < 2) {
                PHPUnit::assertNull($loaded_tx, "found unexpected tx: ".($loaded_tx ? json_encode($loaded_tx->toArray(), 192) : 'null'));
            } else {
                PHPUnit::assertNotNull($loaded_tx, "missing tx $offset");
                PHPUnit::assertEquals($created_tx->toArray(), $loaded_tx->toArray());
            }
        }
    }


}
