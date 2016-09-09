<?php

use \PHPUnit_Framework_Assert as PHPUnit;


class AssetInfoTest extends TestCase {

    protected $useDatabase = true;

    public function testAssetInfoGetClearedWhenIssuanceTransactionIsReceived() {

        $mock_cache = Mockery::mock('Tokenly\CounterpartyAssetInfoCache\Cache');
        $mock_cache->shouldReceive('forget')->with('NEWCOIN')->once();
        app()->bind('Tokenly\CounterpartyAssetInfoCache\Cache', function() use ($mock_cache) {
            return $mock_cache;
        });

        // trigger a fake issuance transaction for NEWCOIN
        $tx_helper = app('SampleTransactionsHelper');
        $parsed_tx = $tx_helper->loadSampleTransaction('sample_xcp_parsed_issuance_01.json');
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');
        $block_event_context = app('App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContextFactory')->newBlockEventContext();
        Event::fire('xchain.tx.confirmed', [$parsed_tx, 1, 101, $block, $block_event_context]);

        // verifies that forget was called once
        Mockery::close();
    }

}
