<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BackfillTest extends TestCase {

    protected $useDatabase = true;


    public function testInitialFindMissingBlocks() {
        // init mocks
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $blockchain_store = $this->app->make('App\Blockchain\Block\BlockChainStore');
        $block_events = $blockchain_store->loadMissingBlockEventsFromBitcoind('BLOCKHASH04');
        PHPUnit::assertCount(1, $block_events);

    }



}
