<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BlockHandlerTest extends TestCase {

    protected $useDatabase = true;

    public function testBlockHandler() {
        // init mocks
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

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
        PHPUnit::assertEquals('f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58', $mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals('8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8', $mock_calls['btcd'][3]['args'][0]);
    }


    public function testDuplicateBlockErrorStillHandlesTransactions() {
        // init mocks
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);


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
        PHPUnit::assertEquals('f88d98717dacb985e3ad49ffa66b8562d8194f1885f58425e1c8582ce2ac5b58', $mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals('8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8', $mock_calls['btcd'][3]['args'][0]);
    }


    public function testDuplicateBlockErrorSendsNotificationsOnce() {
        // init mocks
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $queue_manager = app('Illuminate\Queue\QueueManager');
        $queue_manager->addConnector('sync', function() {
            return new \TestMemorySyncConnector();
        });

        // drain the queue to start
        $queue_manager->connection('notifications_out')->drain();

        // get a single sample user
        $sample_user = app('UserHelper')->createSampleUser(['webhook_endpoint' => null]);

        // add a monitor address for 12iVwKP7jCPnuYy7jbAbyXnZ3FxvgLwvGK
        $created_address = app('MonitoredAddressHelper')->createSampleMonitoredAddress($sample_user, ['address' => '1KUsjZKrkd7LYRV7pbnNJtofsq1HAiz6MF']);

        // build and process a block event with the same transaction ID
        $block_event = $this->buildBlockEvent([
            'tx' => [
                "000000000000000000000000000000000000000000000000000000000000001a",
                "8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8",
            ],
        ]);
        $network_handler_factory = app('App\Handlers\XChain\Network\Factory\NetworkHandlerFactory');
        $block_handler = $network_handler_factory->buildBlockHandler($block_event['network']);
        $block_handler->processBlock($block_event);

        // check mock calls
        $btcd_calls = $mock_calls['btcd'];
        PHPUnit::assertEquals('000000000000000000000000000000000000000000000000000000000000001a', $btcd_calls[0]['args'][0]);
        PHPUnit::assertEquals('8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8', $btcd_calls[3]['args'][0]);

        // check notifications out
        $notifications = $this->getActualNotifications($queue_manager);
        PHPUnit::assertCount(1, $notifications);
        $payload = json_decode($notifications[0]['payload'], true);
        PHPUnit::assertEquals(['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD'], $payload['sources']);
        PHPUnit::assertEquals(['1KUsjZKrkd7LYRV7pbnNJtofsq1HAiz6MF'], $payload['destinations']);

        // process the block a second time
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        // build and process a block event with the same transaction ID
        $block_event = $this->buildBlockEvent([
            'tx' => [
                "000000000000000000000000000000000000000000000000000000000000001a",
                "8de3c8666c40f73ae13df0206e9caf83c075c51eb54349331aeeba130b7520c8",
            ],
        ]);
        $network_handler_factory = app('App\Handlers\XChain\Network\Factory\NetworkHandlerFactory');
        $block_handler = $network_handler_factory->buildBlockHandler($block_event['network']);
        $block_handler->processBlock($block_event);

        // check that notifications out is 0 this time
        $notifications = $this->getActualNotifications($queue_manager);
        PHPUnit::assertCount(0, $notifications);

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

    protected function getActualNotifications($queue_manager) {
        $all_actual_notifications = [];

        $safety = 999;
        $done = false;
        while (!$done AND $safety > 0) {
            $raw_queue_entry = $queue_manager
                ->connection('notifications_out')
                ->pop();
            if ($raw_queue_entry) {
                $all_actual_notifications[] = json_decode($raw_queue_entry, true);
                if (--$safety <= 0) { throw new Exception("Looped too many times", 1); }
            } else {
                $done = true;
            }
        }

        return $all_actual_notifications;
    }

}
