<?php

use App\Repositories\MonitoredAddressRepository;
use App\Repositories\TransactionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\QueueManager;
use Symfony\Component\Yaml\Yaml;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

/**
*  ScenarioRunner
*/
class ScenarioRunner
{

    function __construct(Application $app, Dispatcher $events, QueueManager $queue_manager, MonitoredAddressRepository $monitored_address_repository, MonitoredAddressHelper $monitored_address_helper, SampleBlockHelper $sample_block_helper, TransactionRepository $transaction_repository, UserHelper $user_helper) {
        $this->app                          = $app;
        $this->events                       = $events;
        $this->queue_manager                = $queue_manager;
        $this->monitored_address_repository = $monitored_address_repository;
        $this->monitored_address_helper     = $monitored_address_helper;
        $this->sample_block_helper          = $sample_block_helper;
        $this->transaction_repository       = $transaction_repository;
        $this->user_helper                  = $user_helper;

        $this->queue_manager->addConnector('sync', function()
        {
            return new \TestMemorySyncConnector();
        });
    }

    public function init($test_case) {
        if (!isset($this->mocks_inited)) {
            $this->mocks_inited = true;

            $mock_builder = new \InsightAPIMockBuilder();
            $mock_builder->installMockInsightClient($this->app, $test_case);

        }

        return $this;
    }

    public function runScenarioByNumber($scenario_number) {
        $scenario_data = $this->loadScenarioByNumber($scenario_number);
        return $this->runScenario($scenario_data);
    }

    public function loadScenarioByNumber($scenario_number) {
        $filename = "scenario".sprintf('%02d', $scenario_number).".yml";
        return $this->loadScenario($filename);
    }

    public function loadScenario($filename) {
        $filepath = base_path().'/tests/fixtures/scenarios/'.$filename;
        return Yaml::parse(file_get_contents($filepath));
    }

    public function runScenario($scenario_data) {
        $auto_backfill = (!isset($scenario_data['meta']['autoBackfill']) OR $scenario_data['meta']['autoBackfill']);
        if ($auto_backfill) {
            $this->autoBackfillBlock();
        }

        // set up the scenario
        $this->addMonitoredAddresses($scenario_data['monitoredAddresses']);

        // process transactions
        foreach ($scenario_data['events'] as $raw_event) {
            $event = $raw_event;
            unset($event['type']);
            switch ($raw_event['type']) {
                case 'transaction':
                    $raw_transaction_event = $event;
                    $transaction_event = $this->normalizeTransactionEvent($raw_transaction_event);
                    $this->processTransactionEvent($transaction_event);
                    break;
                
                case 'block':
                    $block_event = $this->normalizeBlockEvent($event);
                    $this->processBlockEvent($block_event);
                    break;
                
                default:
                    throw new Exception("Unknown event type {$raw_event['type']}", 1);
                    break;
            }
        }
    }

    public function validateScenario($scenario_data) {
        $meta = isset($scenario_data['meta']) ? $scenario_data['meta'] : [];
        if (isset($scenario_data['notifications'])) { $this->validateNotifications($scenario_data['notifications'], $meta); }
        if (isset($scenario_data['transaction_rows'])) { $this->validateTransactionRows($scenario_data['transaction_rows']); }
    }

    public function transactionEventToParsedTransaction($raw_transaction_event) {
        return $this->normalizeTransactionEvent($raw_transaction_event);
    }


    ////////////////////////////////////////////////////////////////////////
    // Validate Notifications

    protected function validateNotifications($notifications, $meta) {
        foreach ($notifications as $offset => $raw_expected_notification) {
            // get actual notification
            $actual_notification = $this->getActualNotification();

            // normalize expected notification
            list($expected_notification, $meta) = $this->resolveExpectedNotificationMeta($raw_expected_notification);
            if ($expected_notification['event'] == 'block') {
                $expected_notification = $this->normalizeExpectedBlockNotification($expected_notification, $actual_notification);
            } else {
                $expected_notification = $this->normalizeExpectedReceiveNotification($expected_notification, $actual_notification);
            }

            $this->validateNotification($expected_notification, $actual_notification);
        }

        // check extra notifications
        $allow_extra_notifications = isset($meta['allowExtraNotifications']) ? $meta['allowExtraNotifications'] : false;
        if (!$allow_extra_notifications) {
            $actual_notifications = [];
            while ($actual_notification = $this->getActualNotification()) {
                $actual_notifications[] = $actual_notification;
            }
            if ($actual_notifications) {
                throw new Exception("Found ".count($actual_notifications)." unexpected extra notification(s): ".substr(rtrim(json_encode($actual_notifications, 192)), 0, 400), 1);
            }
        }
    }

    protected function validateNotification($expected_notification, $actual_notification) {
        PHPUnit::assertNotEmpty($actual_notification, "Missing notification ".json_encode($expected_notification, 192));
        PHPUnit::assertEquals($expected_notification, $actual_notification, "Notification mismatch.  Actual notification: ".json_encode($actual_notification, 192));
    }

    protected function getActualNotification() {
        $raw_queue_entry = $this->queue_manager
            ->connection('notifications_out')
            ->pop();
        if ($raw_queue_entry) { $raw_queue_entry = json_decode($raw_queue_entry, true); }
        return $raw_queue_entry ? json_decode($raw_queue_entry['payload'], true) : [];
    }


    protected function resolveExpectedNotificationMeta($raw_expected_notification) {
        // get meta
        $meta = [];
        if (isset($raw_expected_notification['meta'])) {
            $meta = $raw_expected_notification['meta'];
            unset($raw_expected_notification['meta']);
        }

        // load from baseFilename
        if (isset($meta['baseFilename'])) {
            $sample_filename = $meta['baseFilename'];

            $default = Yaml::parse(file_get_contents(base_path().'/tests/fixtures/notifications/'.$sample_filename));
            $expected_notification = array_replace_recursive($default, $raw_expected_notification);
        } else {
            $expected_notification = $raw_expected_notification;
        }

        return [$expected_notification, $meta];
    }


    protected function normalizeExpectedReceiveNotification($expected_notification, $actual_notification) {
        $normalized_expected_notification = [];


        if (isset($expected_notification['sources'])) {
            $normalized_expected_notification['sources'] = is_array($expected_notification['sources']) ? $expected_notification['sources'] : [$expected_notification['sources']];
        }
        if (isset($expected_notification['destinations'])) {
            $normalized_expected_notification['destinations'] = is_array($expected_notification['destinations']) ? $expected_notification['destinations'] : [$expected_notification['destinations']];
        }

        ///////////////////
        // EXPECTED
        foreach (['txid','quantity','asset','notifiedAddress','event','network',] as $field) {
            if (isset($expected_notification[$field])) { $normalized_expected_notification[$field] = $expected_notification[$field]; }
        }
        ///////////////////

        ///////////////////
        // OPTIONAL
        foreach (['confirmations','confirmed','counterpartyTx','bitcoinTx','transactionTime','notificationId','notifiedAddressId','webhookEndpoint','blockSeq','confirmationTime',] as $field) {
            if (isset($expected_notification[$field])) {
                if (is_array($expected_notification[$field])) {
                    $normalized_expected_notification[$field] = array_replace_recursive(isset($actual_notification[$field]) ? $actual_notification[$field] : [], $expected_notification[$field]);
                } else {
                    $normalized_expected_notification[$field] = $expected_notification[$field];
                }
            } else if (isset($actual_notification[$field])) {
                $normalized_expected_notification[$field] = $actual_notification[$field];
            }
        }
        ///////////////////

        ///////////////////
        // Special
        // build satoshis
        $normalized_expected_notification['quantitySat'] = CurrencyUtil::valueToSatoshis($normalized_expected_notification['quantity']);
        // blockhash
        if (isset($expected_notification['blockhash'])) {
            $normalized_expected_notification['bitcoinTx']['blockhash'] = $expected_notification['blockhash'];
        }
        if (isset($normalized_expected_notification['counterpartyTx']) AND $normalized_expected_notification['counterpartyTx']) {
            $normalized_expected_notification['counterpartyTx']['quantity'] = $normalized_expected_notification['quantity'];
            $normalized_expected_notification['counterpartyTx']['quantitySat'] = CurrencyUtil::valueToSatoshis($normalized_expected_notification['quantity']);
        }
        ///////////////////



        return $normalized_expected_notification;
    }

    protected function normalizeExpectedBlockNotification($expected_notification, $actual_notification) {
        $normalized_expected_notification = [];



        ///////////////////
        // EXPECTED
        foreach (['event','hash','height','network',] as $field) {
            if (isset($expected_notification[$field])) { $normalized_expected_notification[$field] = $expected_notification[$field]; }
        }
        ///////////////////

        ///////////////////
        // OPTIONAL
        foreach (['notificationId','previousblockhash','time',] as $field) {
            if (isset($expected_notification[$field])) { $normalized_expected_notification[$field] = $expected_notification[$field]; }
                else if (isset($actual_notification[$field])) { $normalized_expected_notification[$field] = $actual_notification[$field]; }
        }
        ///////////////////

        ///////////////////
        // Special
        // if (isset($expected_notification['blockhash'])) {
        //     $normalized_expected_notification['bitcoinTx']['blockhash'] = $expected_notification['blockhash'];
        // }
        ///////////////////


        return $normalized_expected_notification;
    }


    ////////////////////////////////////////////////////////////////////////
    // Validate Transaction Rows

    protected function validateTransactionRows($transaction_rows) {
        foreach ($transaction_rows as $raw_expected_transaction_row) {
            // get actual transaction_row
            $actual_transaction_row = $this->getActualTransactionRow($raw_expected_transaction_row['txid']);
            if (!$actual_transaction_row) { throw new Exception("Transaction {$raw_expected_transaction_row['txid']} not found", 1); }

            // normalize expected transaction_row
            $expected_transaction_row = $this->normalizeExpectedTransactionRow($raw_expected_transaction_row, $actual_transaction_row);

            $this->validateTransactionRow($expected_transaction_row, $actual_transaction_row);
        }
    }

    protected function validateTransactionRow($expected_transaction_row, $actual_transaction_row) {
        PHPUnit::assertEquals($expected_transaction_row, $actual_transaction_row);
    }

    protected function getActualTransactionRow($txid) {
        $model = $this->transaction_repository->findByTXID($txid);
        if (!$model) { return null; }
        return $model->attributesToArray();
    }

    protected function normalizeExpectedTransactionRow($raw_expected_transaction_row, $actual_transaction_row) {
        $normalized_expected_transaction_row = array_merge($actual_transaction_row, $raw_expected_transaction_row['expected']);
        return $normalized_expected_transaction_row;
    }


    ////////////////////////////////////////////////////////////////////////
    // Blocks
    
    protected function autoBackfillBlock($block_name=null) {
        if ($block_name === null) {
            $block_name = 'default_backfill_block_01.json';
        }
        $block_model = $this->sample_block_helper->createSampleBlock($block_name);
        return;
    }

    ////////////////////////////////////////////////////////////////////////
    // Monitored Addresses

    protected function addMonitoredAddresses($addresses) {
        foreach($addresses as $attributes) {
            $this->monitored_address_repository->createWithUser($this->getSampleUser(), $this->monitored_address_helper->sampleDBVars($attributes));
        }
    }

    protected function getSampleUser() {
        if (!isset($this->sample_user)) {
            $this->sample_user = $this->user_helper->createSampleUser();
        }
        return $this->sample_user;
    }

    ////////////////////////////////////////////////////////////////////////
    // Transaction

    // normalize the transaction
    protected function normalizeTransactionEvent($raw_transaction_event) {
        $meta = isset($raw_transaction_event['meta']) ? $raw_transaction_event['meta'] : [];
        if (isset($meta['baseFilename'])) {
            $sample_filename = $meta['baseFilename'];
        } else {
            $sample_filename = 'default_xcp_parsed_01.json';
        }
        $default = json_decode(file_get_contents(base_path().'/tests/fixtures/transactions/'.$sample_filename), true);
        $normalized_transaction_event = $default;

        if (isset($raw_transaction_event['txid'])) {
            $normalized_transaction_event['txid'] = $raw_transaction_event['txid'];
            $normalized_transaction_event['bitcoinTx']['txid'] = $raw_transaction_event['txid'];
        }

        if (isset($raw_transaction_event['sender'])) {
            $normalized_transaction_event['sources'] = [$raw_transaction_event['sender']];
        }
        if (isset($raw_transaction_event['recipient'])) {
            $normalized_transaction_event['destinations'] = [$raw_transaction_event['recipient']];
        }
        
        // if (isset($raw_transaction_event['isCounterpartyTx'])) {
        //     $normalized_transaction_event['isCounterpartyTx'] = $raw_transaction_event['isCounterpartyTx'];
        //     if (!$raw_transaction_event['isCounterpartyTx']) {
        //         $normalized_transaction_event['counterPartyTxType'] = false;
        //         $normalized_transaction_event['counterpartyTx'] = [];
        //     }
        // }


        if (isset($raw_transaction_event['asset'])) { $normalized_transaction_event['asset'] = $raw_transaction_event['asset']; }

        if (isset($raw_transaction_event['quantity'])) {
            // $normalized_transaction_event['quantity'] = $raw_transaction_event['quantity'];
            // $normalized_transaction_event['quantitySat'] = CurrencyUtil::valueToSatoshis($raw_transaction_event['quantity']);
            $normalized_transaction_event['values'] = [$normalized_transaction_event['destinations'][0] => $raw_transaction_event['quantity']];
            $normalized_transaction_event['counterpartyTx']['quantity'] = $raw_transaction_event['quantity'];
            $normalized_transaction_event['counterpartyTx']['quantitySat'] = CurrencyUtil::valueToSatoshis($raw_transaction_event['quantity']);
        }

        if (isset($raw_transaction_event['confirmations'])) { $normalized_transaction_event['bitcoinTx']['confirmations'] = $raw_transaction_event['confirmations']; }

        // timestamp
        if (isset($raw_transaction_event['timestamp'])) {
            $normalized_transaction_event['timestamp'] = $raw_transaction_event['timestamp'];
            $normalized_transaction_event['bitcoinTx']['timestamp'] = $raw_transaction_event['timestamp'];
        }


        // timing
        if (isset($raw_transaction_event['mempool'])) {}
        if (isset($raw_transaction_event['blockId'])) {}

        return $normalized_transaction_event;
    }

    protected function processTransactionEvent($transaction_event) {
        // run the job
        // echo "\$transaction_event:\n".json_encode($transaction_event, 192)."\n";
        $block_seq = 0;
        $block_confirmation_time = 0;
        $this->events->fire('xchain.tx.received', [$transaction_event, (isset($transaction_event['bitcoinTx']['confirmations']) ? $transaction_event['bitcoinTx']['confirmations'] : 0), $block_seq, $block_confirmation_time, ]);
    }

    ////////////////////////////////////////////////////////////////////////
    // Block

    protected function normalizeBlockEvent($raw_block_event) {
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

        ///////////////////
        // SPECIAL
        // if (isset($raw_block_event['previousblockhash'])) { $normalized_block_event['previousblockhash'] = $raw_block_event['previousblockhash']; }
        ///////////////////

        // echo "\$normalized_block_event:\n".json_encode($normalized_block_event, 192)."\n";
        return $normalized_block_event;
    }

    protected function processBlockEvent($block_event) {
        // run the job
        $this->events->fire('xchain.block.received', [$block_event]);
    }





}