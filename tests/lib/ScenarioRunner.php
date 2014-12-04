<?php

use App\Repositories\MonitoredAddressRepository;
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

    function __construct(Dispatcher $events, QueueManager $queue_manager, MonitoredAddressRepository $monitored_address_repository, MonitoredAddressHelper $monitored_address_helper) {
        // $this->app = $app;
        $this->events                       = $events;
        $this->queue_manager                = $queue_manager;
        $this->monitored_address_repository = $monitored_address_repository;
        $this->monitored_address_helper     = $monitored_address_helper;

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
        foreach ($scenario_data['transactions'] as $raw_transaction_event) {
            $transaction_event = $this->normalizeTransactionEvent($raw_transaction_event);
            $this->processTransactionEvent($transaction_event);
        }
    }

    public function validateScenario($scenario_data) {
        foreach ($scenario_data['notifications'] as $raw_expected_notification) {
            // get actual notification
            $actual_notification = $this->getActualNotification();

            // normalize expected notification
            $expected_notification = $this->normalizeExpectedNotification($raw_expected_notification, $actual_notification);

            $this->validateNotification($expected_notification, $actual_notification);
        }
    }


    protected function addMonitoredAddresses($addresses) {
        foreach($addresses as $attributes) {
            $this->monitored_address_repository->create($this->monitored_address_helper->sampleDBVars($attributes));
        }
    }

    // normalize the transaction
    protected function normalizeTransactionEvent($raw_transaction_event) {
        $meta = isset($raw_transaction_event['meta']) ? $raw_transaction_event['meta'] : [];
        if (isset($meta['baseTransactionFilename'])) {
            $sample_filename = $meta['baseTransactionFilename'];
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
        $this->events->fire('xchain.tx.received', [$transaction_event]);
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

        if (isset($raw_expected_notification['sources'])) {
            $normalized_expected_notification['sources'] = is_array($raw_expected_notification['sources']) ? $raw_expected_notification['sources'] : [$raw_expected_notification['sources']];
        }
        if (isset($raw_expected_notification['destinations'])) {
            $normalized_expected_notification['destinations'] = is_array($raw_expected_notification['destinations']) ? $raw_expected_notification['destinations'] : [$raw_expected_notification['destinations']];
        }

        // required
        foreach (['txid','isCounterpartyTx','quantity','asset','notifiedAddress','event',] as $field) {
            if (isset($raw_expected_notification[$field])) { $normalized_expected_notification[$field] = $raw_expected_notification[$field]; }
        }

        // copy sat
        $normalized_expected_notification['quantitySat'] = CurrencyUtil::valueToSatoshis($normalized_expected_notification['quantity']);

        // not required
        foreach (['confirmations','confirmed','counterpartyTx','bitcoinTx','transactionTime','notificationId','webhookEndpoint',] as $field) {
            if (isset($raw_expected_notification[$field])) { $normalized_expected_notification[$field] = $raw_expected_notification[$field]; }
                else if (isset($actual_notification[$field])) { $normalized_expected_notification[$field] = $actual_notification[$field]; }
        }


        return $normalized_expected_notification;
    }

    protected function validateNotification($expected_notification, $actual_notification) {
        PHPUnit::assertEquals($expected_notification, $actual_notification);
    }

}