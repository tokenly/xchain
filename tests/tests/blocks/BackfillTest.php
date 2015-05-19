<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BackfillTest extends TestCase {

    protected $useDatabase = true;


    public function testInitialFindMissingBlocks() {
        // init mocks
        $mock_builder = new \InsightAPIMockBuilder();
        $mock_builder->installMockInsightClient($this->app, $this);

        $blockchain_store = $this->app->make('App\Blockchain\Block\BlockChainStore');
        $block_events = $blockchain_store->loadMissingBlockEventsFromInsight('BLOCKHASH04');
        PHPUnit::assertCount(1, $block_events);

    }



}
