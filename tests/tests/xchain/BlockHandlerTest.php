<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BlockHandlerTest extends TestCase {

    protected $useDatabase = true;

    public function testBlockHandler() {
        // init mocks
        $mock_builder = new \InsightAPIMockBuilder();
        $mock_calls = $mock_builder->installMockInsightClient($this->app, $this);


        // build and process a block event
        $block_event = $this->buildBlockEvent([
            'tx' => [
                "f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58",
                "8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8",
            ],
        ]);
        $network_handler_factory = app('App\Handlers\XChain\Network\Factory\NetworkHandlerFactory');
        $block_handler = $network_handler_factory->buildBlockHandler($block_event['network']);
        $block_handler->processBlock($block_event);

        // check mock calls
        PHPUnit::assertEquals('f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58', $mock_calls['insight'][0]['args'][0]);
        PHPUnit::assertEquals('8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8', $mock_calls['insight'][1]['args'][0]);
    }


    public function testDuplicateBlockErrorStillHandlesTransactions() {
        // init mocks
        $mock_builder = new \InsightAPIMockBuilder();
        $mock_calls = $mock_builder->installMockInsightClient($this->app, $this);


        // insert a block that will be a duplicate
        $created_block_model_1 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => '000000000000000015f697b296584d9d443d2225c67df9033157a9efe4a8faa0',
            'height' => 333000,
            'parsed_block' => ['height' => 333000]
        ]);


        // build and process a block event with the same transaction ID
        $block_event = $this->buildBlockEvent([
            'tx' => [
                "f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58",
                "8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8",
            ],
        ]);
        $network_handler_factory = app('App\Handlers\XChain\Network\Factory\NetworkHandlerFactory');
        $block_handler = $network_handler_factory->buildBlockHandler($block_event['network']);
        $block_handler->processBlock($block_event);

        // check mock calls
        PHPUnit::assertEquals('f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58', $mock_calls['insight'][0]['args'][0]);
        PHPUnit::assertEquals('8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8', $mock_calls['insight'][1]['args'][0]);
    }


    public function testDuplicateBlockErrorSendsNotificationsOnce() {
        // init mocks
        $mock_builder = new \InsightAPIMockBuilder();
        $mock_calls = $mock_builder->installMockInsightClient($this->app, $this);


        // insert a block that will be a duplicate
        $created_block_model_1 = $this->blockHelper()->createSampleBlock('default_parsed_block_01.json', [
            'hash' => '000000000000000015f697b296584d9d443d2225c67df9033157a9efe4a8faa0',
            'height' => 333000,
            'parsed_block' => ['height' => 333000]
        ]);


        // build and process a block event with the same transaction ID
        $block_event = $this->buildBlockEvent([
            'tx' => [
                "f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58",
                "8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8",
            ],
        ]);
        $network_handler_factory = app('App\Handlers\XChain\Network\Factory\NetworkHandlerFactory');
        $block_handler = $network_handler_factory->buildBlockHandler($block_event['network']);
        $block_handler->processBlock($block_event);

        // check mock calls
        PHPUnit::assertEquals('f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58', $mock_calls['insight'][0]['args'][0]);
        PHPUnit::assertEquals('8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8', $mock_calls['insight'][1]['args'][0]);
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function blockHelper() {
        if (!isset($this->sample_block_helper)) { $this->sample_block_helper = $this->app->make('SampleBlockHelper'); }
        return $this->sample_block_helper;
    }

    protected function buildBlockEvent($raw_block_event) {
        $meta = isset($raw_block_event['meta']) ? $raw_block_event['meta'] : [];
        if (isset($meta['baseBlockFilename'])) {
            $sample_filename = $meta['baseBlockFilename'];
        } else {
            $sample_filename = 'default_parsed_block_01.json';
        }
        $default = json_decode(file_get_contents(base_path().'/tests/fixtures/blocks/'.$sample_filename), true);
        $normalized_block_event = $default;

        ///////////////////
        // OPTIONAL
        foreach (['hash','tx','height','previousblockhash','time',] as $field) {
            if (isset($raw_block_event[$field])) { $normalized_block_event[$field] = $raw_block_event[$field]; }
        }
        ///////////////////

        // echo "\$normalized_block_event:\n".json_encode($normalized_block_event, 192)."\n";
        return $normalized_block_event;
    }

}
