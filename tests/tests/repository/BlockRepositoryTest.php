<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BlockRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddSampleBlock()
    {
        // insert
        $block_model = $this->blockHelper()->createSampleBlock();

        // load from repo
        $block_repo = $this->app->make('App\Repositories\BlockRepository');
        $loaded_block_model = $block_repo->findByHash($block_model['hash']);
        PHPUnit::assertNotEmpty($loaded_block_model);
        PHPUnit::assertEquals($block_model['hash'], $loaded_block_model['hash']);
        PHPUnit::assertEquals(333000, $loaded_block_model['parsed_block']['height']);
        PHPUnit::assertEquals(333000, $loaded_block_model['height']);
    }


    public function testFindBlockByHash()
    {
        // insert
        $created_block_model = $this->blockHelper()->createSampleBlock();

        // load from repo
        $block_repo = $this->app->make('App\Repositories\BlockRepository');
        $loaded_block_model = $block_repo->findByHash($created_block_model['hash']);
        PHPUnit::assertNotEmpty($loaded_block_model);
        PHPUnit::assertEquals($created_block_model['hash'], $loaded_block_model['hash']);
        PHPUnit::assertEquals($created_block_model['height'], $loaded_block_model['height']);
    }

    public function testFindAllAsOfHeight()
    {
        // insert
        $created_block_model_1 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH01', 'height' => 333000, 'parsed_block' => ['height' => 333000]]);
        $created_block_model_2 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH02', 'height' => 333001, 'parsed_block' => ['height' => 333001]]);
        // create 3 and 4 in the wrong order
        $created_block_model_4 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH04', 'height' => 333003, 'parsed_block' => ['height' => 333003]]);
        $created_block_model_3 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH03', 'height' => 333002, 'parsed_block' => ['height' => 333002]]);

        // load all as of 333003
        $block_repo = $this->app->make('App\Repositories\BlockRepository');
        $loaded_block_models = $block_repo->findAllAsOfHeight(333003);
        PHPUnit::assertNotEmpty($loaded_block_models);
        PHPUnit::assertCount(1, $loaded_block_models->all());
        PHPUnit::assertEquals('BLOCKHASH04', $loaded_block_models[0]['hash']);

        // load all as of 333002
        $block_repo = $this->app->make('App\Repositories\BlockRepository');
        $loaded_block_models = $block_repo->findAllAsOfHeight(333002);
        PHPUnit::assertNotEmpty($loaded_block_models);
        PHPUnit::assertCount(2, $loaded_block_models->all());
        PHPUnit::assertEquals('BLOCKHASH03', $loaded_block_models[0]['hash']);
        PHPUnit::assertEquals('BLOCKHASH04', $loaded_block_models[1]['hash']);
    }

    public function testFindLatestBlockHeight()
    {
        $block_repo = $this->app->make('App\Repositories\BlockRepository');

        // insert
        $created_block_model_1 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH01', 'height' => 333000, 'parsed_block' => ['height' => 333000]]);
        $created_block_model_2 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH02', 'height' => 333001, 'parsed_block' => ['height' => 333001]]);

        // highest
        PHPUnit::assertEquals(333001, $block_repo->findLatestBlockHeight());

        $created_block_model_4 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH04', 'height' => 333003, 'parsed_block' => ['height' => 333003]]);
        $created_block_model_3 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH03', 'height' => 333002, 'parsed_block' => ['height' => 333002]]);

        // highest
        PHPUnit::assertEquals(333003, $block_repo->findLatestBlockHeight());
    }

    public function testDeleteBlockByHash()
    {
        // insert
        $created_block_model = $this->blockHelper()->createSampleBlock();
        $block_repo = $this->app->make('App\Repositories\BlockRepository');

        // delete
        PHPUnit::assertTrue($block_repo->deleteByHash($created_block_model['hash']));

        // load from repo
        $loaded_block_model = $block_repo->findByHash($created_block_model['hash']);
        PHPUnit::assertEmpty($loaded_block_model);
    }



    protected function blockHelper() {
        if (!isset($this->sample_block_helper)) { $this->sample_block_helper = $this->app->make('SampleBlockHelper'); }
        return $this->sample_block_helper;
    }

}
