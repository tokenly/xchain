<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BlockChainStoreTest extends TestCase {

    protected $useDatabase = true;

    public function testFindAllAsOfHeightWithChain()
    {
        // insert
        $created_block_model_1 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH01BASE01',
            'height' => 333000,
            'parsed_block' => ['height' => 333000]
        ]);
        $created_block_model_2 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH02FORKAAA',
            'previousblockhash' => 'BLOCKHASH01BASE01',
            'height' => 333001,
            'parsed_block' => ['height' => 333001]
        ]);
        $created_block_model_3 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH02FORKBBB',
            'previousblockhash' => 'BLOCKHASH01BASE01',
            'height' => 333002,
            'parsed_block' => ['height' => 333002]
        ]);
        $created_block_model_4 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH03FORKBBB',
            'previousblockhash' => 'BLOCKHASH02FORKBBB',
            'height' => 333003,
            'parsed_block' => ['height' => 333003]
        ]);
        $created_block_model_4 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH04FORKBBB',
            'previousblockhash' => 'BLOCKHASH03FORKBBB',
            'height' => 333004,
            'parsed_block' => ['height' => 333004]
        ]);

        $blockchain_repo = $this->app->make('App\Blockchain\Block\BlockChainStore');

        // load all on chain BLOCKHASH02FORKAAA
        $loaded_block_models = $blockchain_repo->findAllAsOfHeightEndingWithBlockhash(333000, 'BLOCKHASH02FORKAAA');
        PHPUnit::assertCount(2, $loaded_block_models);
        PHPUnit::assertEquals('BLOCKHASH01BASE01', $loaded_block_models[0]['hash']);
        PHPUnit::assertEquals('BLOCKHASH02FORKAAA', $loaded_block_models[1]['hash']);

        // load all on chain BLOCKHASH02FORKBBB
        $loaded_block_models = $blockchain_repo->findAllAsOfHeightEndingWithBlockhash(333000, 'BLOCKHASH02FORKBBB');
        PHPUnit::assertCount(2, $loaded_block_models);
        PHPUnit::assertEquals('BLOCKHASH01BASE01', $loaded_block_models[0]['hash']);
        PHPUnit::assertEquals('BLOCKHASH02FORKBBB', $loaded_block_models[1]['hash']);

        // load all on chain BLOCKHASH04FORKBBB
        $loaded_block_models = $blockchain_repo->findAllAsOfHeightEndingWithBlockhash(333000, 'BLOCKHASH04FORKBBB');
        PHPUnit::assertCount(4, $loaded_block_models);
        PHPUnit::assertEquals('BLOCKHASH01BASE01', $loaded_block_models[0]['hash']);
        PHPUnit::assertEquals('BLOCKHASH02FORKBBB', $loaded_block_models[1]['hash']);
        PHPUnit::assertEquals('BLOCKHASH03FORKBBB', $loaded_block_models[2]['hash']);
        PHPUnit::assertEquals('BLOCKHASH04FORKBBB', $loaded_block_models[3]['hash']);

    }

    public function testFindMissingBlocks() {
        // init mocks
        $mock_builder = new \InsightAPIMockBuilder();
        $mock_builder->installMockInsightClient($this->app, $this);

        // insert
        $created_block_model_1 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH01BASE01',
            'height' => 333000,
            'parsed_block' => ['height' => 333000]
        ]);
        $created_block_model_2 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH02',
            'previousblockhash' => 'BLOCKHASH01BASE01',
            'height' => 333001,
            'parsed_block' => ['height' => 333001]
        ]);
        // MISSING BLOCKHASH03 and BLOCKHASH04
        $created_block_model_3 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => 'BLOCKHASH05',
            'previousblockhash' => 'BLOCKHASH04',
            'height' => 333004,
            'parsed_block' => ['height' => 333004]
        ]);

        $blockchain_store = $this->app->make('App\Blockchain\Block\BlockChainStore');
        $block_events = $blockchain_store->loadMissingBlockEventsFromInsight('BLOCKHASH04', 4);
        PHPUnit::assertCount(2, $block_events);

        // make sure they were loaded in the correct order
        PHPUnit::assertEquals(['BLOCKHASH03','BLOCKHASH04'], [$block_events[0]['hash'], $block_events[1]['hash']]);
    }



    protected function blockHelper() {
        if (!isset($this->sample_block_helper)) { $this->sample_block_helper = $this->app->make('SampleBlockHelper'); }
        return $this->sample_block_helper;
    }

}
