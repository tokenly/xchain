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

    public function testAssetInfoGetSingle() {
        $mock_xcpd = Mockery::mock('Tokenly\XCPDClient\Client');
        $mock_xcpd->shouldReceive('get_asset_info')->with(['assets' => ['FOOCOIN']])->once()->andReturn([['asset' => 'FOOCOIN', 'divisible' => false, 'supply' => 1000]]);
        $mock_xcpd->shouldReceive('get_issuances')->with([
                'filters' => [
                    ['field' => 'asset',  'op' => '==', 'value' => 'FOOCOIN',],
                    ['field' => 'status', 'op' => '==', 'value' => 'valid',],
                ],
                'order_by' => 'tx_index',
                'order_dir' => 'DESC',
                'limit' => 1,
            ])->once()->andReturn([['asset' => 'FOOCOIN', 'status' => 'valid', 'block_index' => '430000', 'tx_hash' => 'HASH001', 'description' => 'My foocoin']]);
        app()->bind('Tokenly\XCPDClient\Client', function() use ($mock_xcpd) {
            return $mock_xcpd;
        });


        $cache = app('Tokenly\CounterpartyAssetInfoCache\Cache');
        $expected_cached_data = [
            'asset' => 'FOOCOIN',
            'divisible' => false,
            'supply' => 1000,
            'status' => 'valid',
            'tx_hash' => 'HASH001',
            'block_index' => '430000',
        ];

        // call twice - all methods are called only once
        PHPUnit::assertEquals($expected_cached_data, $cache->get('FOOCOIN'));
        PHPUnit::assertEquals($expected_cached_data, $cache->get('FOOCOIN'));

        // verifies that forget was called once
        Mockery::close();
    }

    public function testAssetInfoGetMultiple() {
        $mock_xcpd = Mockery::mock('Tokenly\XCPDClient\Client');
        $mock_xcpd->shouldReceive('get_asset_info')->with(['assets' => ['FOOCOIN','BARCOIN']])->once()->andReturn([
            ['asset' => 'FOOCOIN', 'divisible' => false, 'supply' => 1000],
            ['asset' => 'BARCOIN', 'divisible' => false, 'supply' => 2000],
        ]);
        $mock_xcpd->shouldReceive('get_issuances')->with([
                'filters' => [
                    ['field' => 'asset',  'op' => '==', 'value' => 'FOOCOIN',],
                    ['field' => 'status', 'op' => '==', 'value' => 'valid',],
                ],
                'order_by' => 'tx_index',
                'order_dir' => 'DESC',
                'limit' => 1,
            ])->once()->andReturn([['asset' => 'FOOCOIN', 'status' => 'valid', 'block_index' => '430001', 'tx_hash' => 'HASH001', 'description' => 'My foocoin']]);
        $mock_xcpd->shouldReceive('get_issuances')->with([
                'filters' => [
                    ['field' => 'asset',  'op' => '==', 'value' => 'BARCOIN',],
                    ['field' => 'status', 'op' => '==', 'value' => 'valid',],
                ],
                'order_by' => 'tx_index',
                'order_dir' => 'DESC',
                'limit' => 1,
            ])->once()->andReturn([['asset' => 'BARCOIN', 'status' => 'valid', 'block_index' => '430002', 'tx_hash' => 'HASH002', 'description' => 'My barcoin']]);
        app()->bind('Tokenly\XCPDClient\Client', function() use ($mock_xcpd) {
            return $mock_xcpd;
        });


        $cache = app('Tokenly\CounterpartyAssetInfoCache\Cache');
        $expected_cached_data = [
            [
                'asset' => 'FOOCOIN',
                'divisible' => false,
                'supply' => 1000,
                'status' => 'valid',
                'tx_hash' => 'HASH001',
                'block_index' => '430001',
            ],
            [
                'asset' => 'BARCOIN',
                'divisible' => false,
                'supply' => 2000,
                'status' => 'valid',
                'tx_hash' => 'HASH002',
                'block_index' => '430002',
            ],
        ];

        // call twice - all methods are called only once
        // echo "\$actual_cached_data: ".json_encode($cache->getMultiple(['FOOCOIN', 'BARCOIN']), 192)."\n";
        PHPUnit::assertEquals($expected_cached_data, $cache->getMultiple(['FOOCOIN', 'BARCOIN']));
        PHPUnit::assertEquals($expected_cached_data, $cache->getMultiple(['FOOCOIN', 'BARCOIN']));

        // verifies that forget was called once
        Mockery::close();
    }

    public function testAssetInfoGetFromCacheWithOverlappingMultiple() {
        $mock_xcpd = Mockery::mock('Tokenly\XCPDClient\Client');
        $mock_xcpd->shouldReceive('get_asset_info')->with(['assets' => ['FOOCOIN','BARCOIN']])->once()->andReturn([
            ['asset' => 'FOOCOIN', 'divisible' => false, 'supply' => 1000],
            ['asset' => 'BARCOIN', 'divisible' => false, 'supply' => 2000],
        ]);
        $mock_xcpd->shouldReceive('get_issuances')->with([
                'filters' => [
                    ['field' => 'asset',  'op' => '==', 'value' => 'FOOCOIN',],
                    ['field' => 'status', 'op' => '==', 'value' => 'valid',],
                ],
                'order_by' => 'tx_index',
                'order_dir' => 'DESC',
                'limit' => 1,
            ])->once()->andReturn([['asset' => 'FOOCOIN', 'status' => 'valid', 'block_index' => '430001', 'tx_hash' => 'HASH001', 'description' => 'My foocoin']]);
        $mock_xcpd->shouldReceive('get_issuances')->with([
                'filters' => [
                    ['field' => 'asset',  'op' => '==', 'value' => 'BARCOIN',],
                    ['field' => 'status', 'op' => '==', 'value' => 'valid',],
                ],
                'order_by' => 'tx_index',
                'order_dir' => 'DESC',
                'limit' => 1,
            ])->once()->andReturn([['asset' => 'BARCOIN', 'status' => 'valid', 'block_index' => '430002', 'tx_hash' => 'HASH002', 'description' => 'My barcoin']]);

        $mock_xcpd->shouldReceive('get_asset_info')->with(['assets' => ['BAZCOIN']])->once()->andReturn([
            ['asset' => 'BAZCOIN', 'divisible' => false, 'supply' => 3000],
        ]);
        $mock_xcpd->shouldReceive('get_issuances')->with([
                'filters' => [
                    ['field' => 'asset',  'op' => '==', 'value' => 'BAZCOIN',],
                    ['field' => 'status', 'op' => '==', 'value' => 'valid',],
                ],
                'order_by' => 'tx_index',
                'order_dir' => 'DESC',
                'limit' => 1,
            ])->once()->andReturn([['asset' => 'BAZCOIN', 'status' => 'valid', 'block_index' => '430003', 'tx_hash' => 'HASH003', 'description' => 'My bazcoin']]);

        app()->bind('Tokenly\XCPDClient\Client', function() use ($mock_xcpd) {
            return $mock_xcpd;
        });


        $cache = app('Tokenly\CounterpartyAssetInfoCache\Cache');


        // call first time
        $expected_cached_data = [
            [
                'asset' => 'FOOCOIN',
                'divisible' => false,
                'supply' => 1000,
                'status' => 'valid',
                'tx_hash' => 'HASH001',
                'block_index' => '430001',
            ],
            [
                'asset' => 'BARCOIN',
                'divisible' => false,
                'supply' => 2000,
                'status' => 'valid',
                'tx_hash' => 'HASH002',
                'block_index' => '430002',
            ],
        ];
        PHPUnit::assertEquals($expected_cached_data, $cache->getMultiple(['FOOCOIN', 'BARCOIN']));


        // call second time
        $actual_cached_data = $cache->getMultiple(['FOOCOIN', 'BARCOIN', 'BAZCOIN']);
        $expected_cached_data = [
            [
                'asset' => 'FOOCOIN',
                'divisible' => false,
                'supply' => 1000,
                'status' => 'valid',
                'tx_hash' => 'HASH001',
                'block_index' => '430001',
            ],
            [
                'asset' => 'BARCOIN',
                'divisible' => false,
                'supply' => 2000,
                'status' => 'valid',
                'tx_hash' => 'HASH002',
                'block_index' => '430002',
            ],
            [
                'asset' => 'BAZCOIN',
                'divisible' => false,
                'supply' => 3000,
                'status' => 'valid',
                'tx_hash' => 'HASH003',
                'block_index' => '430003',
            ],
        ];
        PHPUnit::assertEquals($expected_cached_data, $actual_cached_data);

        // verifies that forget was called once
        Mockery::close();
    }

}
