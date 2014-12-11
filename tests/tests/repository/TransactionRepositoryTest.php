<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddXCPTransaction()
    {
        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json');

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($transaction_model['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($transaction_model['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(1, $loaded_transaction_model['is_xcp']);
        PHPUnit::assertEquals(0, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals('00000000000000000347e702fdc4d6ed74dca01844857deb5fec560c25b14d51', $loaded_transaction_model['block_confirmed_hash']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }

    public function testAddBTCTransaction()
    {
        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json');
        $parsed_tx = $transaction_model['parsed_tx'];

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($parsed_tx['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($parsed_tx['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(0, $loaded_transaction_model['is_xcp']);
        PHPUnit::assertEquals(0, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals('00000000000000003a1e5abc2d7af7f38a614d2fcbafe309b7e8aa147d508a9c', $loaded_transaction_model['block_confirmed_hash']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }

    public function testFindByTXID()
    {
        // insert
        $created_transaction_model = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json');

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($created_transaction_model['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($created_transaction_model['id'], $loaded_transaction_model['id']);
        PHPUnit::assertEquals($created_transaction_model['address'], $loaded_transaction_model['address']);
    }

    public function testFindAllTransactionsConfirmedInBlockHashes()
    {
        // insert
        $created_transaction_models = [];
        $created_transaction_models[] = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => 'TX01', 'bitcoinTx' => ['blockhash' => 'BLOCKHASH01']]);
        $created_transaction_models[] = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => 'TX02', 'bitcoinTx' => ['blockhash' => 'BLOCKHASH02']]);
        $created_transaction_models[] = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => 'TX03', 'bitcoinTx' => ['blockhash' => 'BLOCKHASH03']]);

        // load from repo
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $loaded_transaction_models = $tx_repo->findAllTransactionsConfirmedInBlockHashes(['BLOCKHASH02','BLOCKHASH03']);
        PHPUnit::assertNotEmpty($loaded_transaction_models);
        PHPUnit::assertCount(2, $loaded_transaction_models);
        PHPUnit::assertEquals('TX02', $loaded_transaction_models[0]['txid']);
        PHPUnit::assertEquals('TX03', $loaded_transaction_models[1]['txid']);
    }

    public function testDeleteByTXID()
    {
        // insert
        $created_transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json');
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
