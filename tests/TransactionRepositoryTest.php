<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddXCPTransaction()
    {
        $parsed_tx = $this->txHelper()->loadTestData('sample_xcp_parsed_01.json');

        // insert
        $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
        $block_seen = 300000;
        $transaction_model = $tx_repo->create($parsed_tx['txid'], $parsed_tx['isCounterpartyTx'], $block_seen);

        // load from repo
        $loaded_transaction_model = $tx_repo->findByTXID($parsed_tx['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($parsed_tx['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(1, $loaded_transaction_model['is_xcp']);
        PHPUnit::assertEquals(1, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals($block_seen, $loaded_transaction_model['block_seen']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }


    public function testparseTransaction()
    {
         $tx_repo = $this->app->make('App\Repositories\TransactionRepository');
         $raw_tx = $this->txHelper()->loadTestData('sample_btc_raw_01.json');
         // decode
         $builder = app('Tokenly\XChainListener\Builder\TransactionEventBuilder');
         $event = $builder->buildEventData(['ts' => $raw_tx['time'], 'tx' => $raw_tx]);
         echo "\$event:\n".json_encode($event, 192)."\n";
    }


    protected function txHelper() {
        if (!isset($this->sample_tx_helper)) { $this->sample_tx_helper = new SampleTransactionsHelper(); }
        return $this->sample_tx_helper;
    }

}
