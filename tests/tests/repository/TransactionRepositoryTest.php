<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddXCPTransaction()
    {
        // insert
        $block_seen = 300000;
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', $block_seen);

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($transaction_model['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($transaction_model['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(1, $loaded_transaction_model['is_xcp']);
        PHPUnit::assertEquals(1, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals($block_seen, $loaded_transaction_model['block_seen']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }

    public function testAddBTCTransaction()
    {
        // insert
        $block_seen = 300000;
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json', $block_seen);
        $parsed_tx = $transaction_model['parsed_tx'];

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($parsed_tx['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($parsed_tx['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(0, $loaded_transaction_model['is_xcp']);
        PHPUnit::assertEquals(1, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals($block_seen, $loaded_transaction_model['block_seen']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }

    public function testFindByTXID()
    {
        // insert
        $block_seen = 300000;
        $created_transaction_model = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', $block_seen);

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($created_transaction_model['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($created_transaction_model['id'], $loaded_transaction_model['id']);
        PHPUnit::assertEquals($created_transaction_model['address'], $loaded_transaction_model['address']);
    }

    public function testDeleteByTXID()
    {
        // insert
        $block_seen = 300000;
        $created_transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json', $block_seen);
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');

        // delete
        PHPUnit::assertTrue($tx_repo->deleteByTXID($created_transaction_model['txid']));

        // load from repo
        $loaded_transaction_model = $tx_repo->findByTXID($created_transaction_model['txid']);
        PHPUnit::assertEmpty($loaded_transaction_model);
    }



    protected function txHelper() {
        if (!isset($this->sample_tx_helper)) { $this->sample_tx_helper = $this->app->make('SampleTransactionsHelper'); }
        return $this->sample_tx_helper;
    }

}
