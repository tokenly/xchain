<?php

use App\Repositories\MonitoredAddressRepository;
use App\Repositories\TransactionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Symfony\Component\Yaml\Yaml;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

/**
*  ScenarioRunner
*/
class ScenarioRunner
{

    function __construct(Dispatcher $events, QueueManager $queue_manager, MonitoredAddressRepository $monitored_address_repository, MonitoredAddressHelper $monitored_address_helper, TransactionRepository $transaction_repository) {
        // $this->app = $app;
        $this->events                       = $events;
        $this->queue_manager                = $queue_manager;
        $this->monitored_address_repository = $monitored_address_repository;
        $this->monitored_address_helper     = $monitored_address_helper;
        $this->transaction_repository       = $transaction_repository;

        $this->queue_manager->addConnector('sync', function()
        {
            return new \TestMemorySyncConnector();
        });
    }

    public function loadScenario($filename) {
        $filepath = base_path().'/tests/fixtures/scenarios/'.$filename;
        return Yaml::parse($filepath);
    }

    public function runScenario($scenario_data) {
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

    ////////////////////////////////////////////////////////////////////////
    // Validate Notifications

    protected function validateNotifications($notifications, $meta) {
        foreach ($notifications as $offset => $raw_expected_notification) {
            // get actual notification
            $actual_notification = $this->getActualNotification();

            // normalize expected notification
            $expected_notification = $this->normalizeExpectedNotification($raw_expected_notification, $actual_notification);

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
        PHPUnit::assertEquals($expected_notification, $actual_notification);
    }

    protected function getActualNotification() {
        $raw_queue_entry = $this->queue_manager
            ->connection('notifications_out')
            ->pop();
        if ($raw_queue_entry) { $raw_queue_entry = json_decode($raw_queue_entry, true); }
        return $raw_queue_entry ? json_decode($raw_queue_entry['payload'], true) : [];
    }

    protected function normalizeExpectedNotification($raw_expected_notification, $actual_notification) {
        $normalized_expected_notification = [];

        // get meta
        $meta = [];
        if (isset($raw_expected_notification['meta'])) {
            $meta = $raw_expected_notification['meta'];
            unset($raw_expected_notification['meta']);
        }

        // load from baseFilename
        if (isset($meta['baseFilename'])) {
            $sample_filename = $meta['baseFilename'];

            $default = Yaml::parse(base_path().'/tests/fixtures/notifications/'.$sample_filename);
            $raw_expected_notification = array_replace_recursive($default, $raw_expected_notification);
        }


        if (isset($raw_expected_notification['sources'])) {
            $normalized_expected_notification['sources'] = is_array($raw_expected_notification['sources']) ? $raw_expected_notification['sources'] : [$raw_expected_notification['sources']];
        }
        if (isset($raw_expected_notification['destinations'])) {
            $normalized_expected_notification['destinations'] = is_array($raw_expected_notification['destinations']) ? $raw_expected_notification['destinations'] : [$raw_expected_notification['destinations']];
        }

        ///////////////////
        // EXPECTED
        foreach (['txid','isCounterpartyTx','quantity','asset','notifiedAddress','event',] as $field) {
            if (isset($raw_expected_notification[$field])) { $normalized_expected_notification[$field] = $raw_expected_notification[$field]; }
        }
        ///////////////////

        // build satoshis
        $normalized_expected_notification['quantitySat'] = CurrencyUtil::valueToSatoshis($normalized_expected_notification['quantity']);

        ///////////////////
        // OPTIONAL
        foreach (['confirmations','confirmed','counterpartyTx','bitcoinTx','transactionTime','notificationId','webhookEndpoint',] as $field) {
            if (isset($raw_expected_notification[$field])) { $normalized_expected_notification[$field] = $raw_expected_notification[$field]; }
                else if (isset($actual_notification[$field])) { $normalized_expected_notification[$field] = $actual_notification[$field]; }
        }
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
    // Monitored Addresses

    protected function addMonitoredAddresses($addresses) {
        foreach($addresses as $attributes) {
            $this->monitored_address_repository->create($this->monitored_address_helper->sampleDBVars($attributes));
        }
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
        
        if (isset($raw_transaction_event['isCounterpartyTx'])) { $normalized_transaction_event['isCounterpartyTx'] = $raw_transaction_event['isCounterpartyTx']; }


        if (isset($raw_transaction_event['asset'])) { $normalized_transaction_event['asset'] = $raw_transaction_event['asset']; }

        if (isset($raw_transaction_event['quantity'])) {
            $normalized_transaction_event['quantity'] = $raw_transaction_event['quantity'];
            $normalized_transaction_event['quantitySat'] = CurrencyUtil::valueToSatoshis($raw_transaction_event['quantity']);
        }

        if (isset($raw_transaction_event['confirmations'])) { $normalized_transaction_event['bitcoinTx']['confirmations'] = $raw_transaction_event['confirmations']; }


        // timing
        if (isset($raw_transaction_event['mempool'])) {}
        if (isset($raw_transaction_event['blockId'])) {}

        return $normalized_transaction_event;
    }

    protected function processTransactionEvent($transaction_event) {
        // run the job
        // echo "\$transaction_event:\n".json_encode($transaction_event, 192)."\n";
        $this->events->fire('xchain.tx.received', [$transaction_event, isset($transaction_event['bitcoinTx']['confirmations']) ? $transaction_event['bitcoinTx']['confirmations'] : 0]);
    }

    ////////////////////////////////////////////////////////////////////////
    // Block

    protected function normalizeBlockEvent($raw_block_event) {
        $meta = isset($raw_block_event['meta']) ? $raw_block_event['meta'] : [];
        if (isset($meta['baseBlockFilename'])) {
            $sample_filename = $meta['baseBlockFilename'];
        } else {
            $sample_filename = 'sample_parsed_block_01.json';
        }
        $default = json_decode(file_get_contents(base_path().'/tests/fixtures/blocks/'.$sample_filename), true);
        $normalized_block_event = $default;

        ///////////////////
        // OPTIONAL
        foreach (['hash','tx','height',] as $field) {
            if (isset($raw_block_event[$field])) { $normalized_block_event[$field] = $raw_block_event[$field]; }
        }
        ///////////////////

        // echo "\$normalized_block_event:\n".json_encode($normalized_block_event, 192)."\n";
        return $normalized_block_event;
    }

    protected function processBlockEvent($block_event) {
        // run the job
        $this->events->fire('xchain.block.received', [$block_event]);
    }





}