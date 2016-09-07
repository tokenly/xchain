<?php

use App\Blockchain\Composer\ComposerUtil;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\BlockRepository;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

/**
*  ScenarioRunner
*/
class ScenarioRunner
{

    protected $last_send_txid = null;

    function __construct(Application $app, Dispatcher $events, QueueManager $queue_manager, PaymentAddressRepository $payment_address_repository, PaymentAddressHelper $payment_address_helper, MonitoredAddressRepository $monitored_address_repository, MonitoredAddressHelper $monitored_address_helper, EventMonitorHelper $event_monitor_helper, SampleBlockHelper $sample_block_helper, TransactionRepository $transaction_repository, BlockRepository $block_repository, UserRepository $user_repository, UserHelper $user_helper) {
        $this->app                          = $app;
        $this->events                       = $events;
        $this->queue_manager                = $queue_manager;
        $this->payment_address_repository   = $payment_address_repository;
        $this->payment_address_helper       = $payment_address_helper;
        $this->event_monitor_helper         = $event_monitor_helper;
        $this->monitored_address_repository = $monitored_address_repository;
        $this->monitored_address_helper     = $monitored_address_helper;
        $this->sample_block_helper          = $sample_block_helper;
        $this->transaction_repository       = $transaction_repository;
        $this->block_repository             = $block_repository;
        $this->user_repository              = $user_repository;
        $this->user_helper                  = $user_helper;

        $this->queue_manager->addConnector('sync', function()
        {
            return new \TestMemorySyncConnector();
        });

    }

    public function init($test_case) {
        if (!isset($this->mocks_inited)) {
            $this->mocks_inited = true;

            // install mocks
            app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $test_case);

            $this->test_case = $test_case;
        }

        return $this;
    }

    public function initXCPDMock() {
        if (!isset($this->test_case)) { throw new Exception("Test case not found", 1); }
        $this->xcpd_mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this->test_case);

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
        // clear sample blocks
        $this->clearDatabasesForScenario();

        $auto_backfill = (!isset($scenario_data['meta']['autoBackfill']) OR $scenario_data['meta']['autoBackfill']);
        if ($auto_backfill) {
            $this->autoBackfillBlock();
        }

        // install mock
        $this->initXCPDMock();

        // set up the scenario
        $this->addMonitoredAddresses($scenario_data['monitoredAddresses']);
        $this->addEventMonitors(isset($scenario_data['eventMonitors']) ? $scenario_data['eventMonitors'] : null);
        $this->addPaymentAddresses(isset($scenario_data['paymentAddresses']) ? $scenario_data['paymentAddresses'] : []);

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
                
                case 'transfer':
                    $this->processTransferEvent($event);
                    break;
                
                case 'send':
                    $this->processSendEvent($event);
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
        if (isset($scenario_data['accounts'])) { $this->validateAccounts($scenario_data['accounts'], $meta); }
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
        foreach (['confirmations','confirmed','counterpartyTx','bitcoinTx','transactionTime','notificationId','notifiedAddressId','notifiedMonitorId','webhookEndpoint','blockSeq','confirmationTime','transactionFingerprint',] as $field) {
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
        if (!$addresses) { return; }
        foreach($addresses as $raw_attributes) {
            $attributes = $raw_attributes;
            $monitored_address = $this->monitored_address_repository->createWithUser($this->getSampleUser(), $this->monitored_address_helper->sampleDBVars($attributes));
        }
    }

    ////////////////////////////////////////////////////////////////////////
    // Event Monitors

    protected function addEventMonitors($event_monitors) {
        if (!$event_monitors) { return; }
        foreach($event_monitors as $raw_attributes) {
            $attributes = $raw_attributes;

            // create a sample user with no default callback webhook endpoint
            $user = $this->user_helper->createSampleUser(['webhook_endpoint' => null]);

            $event_monitor = $this->event_monitor_helper->newSampleEventMonitor($user, $attributes);
        }
    }

    ////////////////////////////////////////////////////////////////////////
    // Payment Addresses

    protected function addPaymentAddresses($addresses) {
        if (!$addresses) { return; }

        foreach($addresses as $raw_attributes) {
            $attributes = $raw_attributes;
            unset($attributes['accountBalances']);
            unset($attributes['rawAccountBalances']);
            $payment_address = $this->payment_address_repository->createWithUser($this->getSampleUser(), $this->payment_address_helper->sampleVars($attributes));

            // create a default account
            AccountHandler::createDefaultAccount($payment_address);


            // assign balances
            $ledger_entry_repository = app('App\Repositories\LedgerEntryRepository');
            $account_repository = app('App\Repositories\AccountRepository');
            $default_account = $account_repository->findByName('default', $payment_address['id']);

            $btc_balance = 0;

            if (isset($raw_attributes['accountBalances'])) {
                foreach ($raw_attributes['accountBalances'] as $asset => $balance) {
                    $ledger_entry_repository->addCredit($balance, $asset, $default_account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, 'testtxid');

                    if ($asset == 'BTC') { $btc_balance += $balance; }
                }
            }
            if (isset($raw_attributes['rawAccountBalances'])) {
                foreach ($raw_attributes['rawAccountBalances'] as $type_string => $balances) {
                    foreach ($balances as $asset => $balance) {
                        $ledger_entry_repository->addCredit($balance, $asset, $default_account, LedgerEntry::typeStringToInteger($type_string), LedgerEntry::DIRECTION_OTHER, 'testtxid');

                        if ($asset == 'BTC') { $btc_balance += $balance; }
                    }
                }
            }

            // add UTXOs
            if ($btc_balance > 0) {
                $this->payment_address_helper->addUTXOToPaymentAddress($btc_balance, $payment_address);
            }
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

        // Asset first
        if (isset($raw_transaction_event['asset'])) { $normalized_transaction_event['asset'] = $raw_transaction_event['asset']; }
        $asset = $normalized_transaction_event['asset'];

        $original_sender = isset($raw_transaction_event['sender']) ? $raw_transaction_event['sender'] : $normalized_transaction_event['sources'][0];
        $original_recipient = isset($raw_transaction_event['recipient']) ? $raw_transaction_event['recipient'] : $normalized_transaction_event['destinations'][0];
        if (isset($raw_transaction_event['sender'])) {
            $normalized_transaction_event['sources'] = [$raw_transaction_event['sender']];
            if (isset($normalized_transaction_event['counterpartyTx']) AND isset($normalized_transaction_event['counterpartyTx']['sources'])) {
                $normalized_transaction_event['counterpartyTx']['sources'] = [$raw_transaction_event['sender']];
            }
        }
        if (isset($raw_transaction_event['recipient'])) {
            $normalized_transaction_event['destinations'] = [$raw_transaction_event['recipient']];
            if (isset($normalized_transaction_event['counterpartyTx']) AND isset($normalized_transaction_event['counterpartyTx']['destinations'])) {
                $normalized_transaction_event['counterpartyTx']['destinations'] = [$raw_transaction_event['recipient']];
            }

            // vouts
            if (isset($normalized_transaction_event['bitcoinTx']['vout'])) {
                $total_vouts = count($normalized_transaction_event['bitcoinTx']['vout']);
                foreach ($normalized_transaction_event['bitcoinTx']['vout'] as $offset => $vout) {
                    if ($offset < $total_vouts - 1) {
                        $new_recipient = $raw_transaction_event['recipient'];
                        $vout['scriptPubKey']['addresses'] = [$new_recipient];
                    }
                    $normalized_transaction_event['bitcoinTx']['vout'][$offset] = $vout;
                }
            }

            if (isset($normalized_transaction_event['counterpartyTx']) AND $normalized_transaction_event['counterpartyTx']) {
                $received_assets = ComposerUtil::buildAssetQuantities($raw_transaction_event['quantity'], $normalized_transaction_event['asset'], 0, $normalized_transaction_event['counterpartyTx']['dustSize']);
            } else {
                $received_assets = ComposerUtil::buildAssetQuantities($raw_transaction_event['quantity'], $normalized_transaction_event['asset']);
            }
            $normalized_transaction_event['receivedAssets'] = [$raw_transaction_event['recipient'] => $received_assets];

        }
        
        // if (isset($raw_transaction_event['isCounterpartyTx'])) {
        //     $normalized_transaction_event['isCounterpartyTx'] = $raw_transaction_event['isCounterpartyTx'];
        //     if (!$raw_transaction_event['isCounterpartyTx']) {
        //         $normalized_transaction_event['counterPartyTxType'] = false;
        //         $normalized_transaction_event['counterpartyTx'] = [];
        //     }
        // }

        if (isset($raw_transaction_event['quantity'])) {
            // $normalized_transaction_event['quantity'] = $raw_transaction_event['quantity'];
            // $normalized_transaction_event['quantitySat'] = CurrencyUtil::valueToSatoshis($raw_transaction_event['quantity']);
            $normalized_transaction_event['values'] = [$normalized_transaction_event['destinations'][0] => $raw_transaction_event['quantity']];
            if (isset($normalized_transaction_event['counterpartyTx']) AND $normalized_transaction_event['counterpartyTx']) {
                $normalized_transaction_event['counterpartyTx']['quantity'] = $raw_transaction_event['quantity'];
                $normalized_transaction_event['counterpartyTx']['quantitySat'] = CurrencyUtil::valueToSatoshis($raw_transaction_event['quantity']);

                $normalized_transaction_event['spentAssets'] = [$original_sender => ComposerUtil::buildAssetQuantities($raw_transaction_event['quantity'], $normalized_transaction_event['asset'], $normalized_transaction_event['bitcoinTx']['fees'], $normalized_transaction_event['counterpartyTx']['dustSize'])];
            } else {
                // adjust utxos
                foreach ($normalized_transaction_event['bitcoinTx']['vout'] as $offset => $vout) {
                    if ($offset < $total_vouts - 1) {
                        $new_recipient = $raw_transaction_event['recipient'];
                        $vout['value'] = $raw_transaction_event['quantity'];
                    }
                    $normalized_transaction_event['bitcoinTx']['vout'][$offset] = $vout;
                }

                // 
                $normalized_transaction_event['spentAssets'] = [$original_sender => ComposerUtil::buildAssetQuantities($raw_transaction_event['quantity'], 'BTC', $normalized_transaction_event['bitcoinTx']['fees'])];
            }
        }

        // confirmations and blockhash
        if (isset($raw_transaction_event['confirmations'])) { $normalized_transaction_event['bitcoinTx']['confirmations'] = $raw_transaction_event['confirmations']; }
        if (isset($raw_transaction_event['blockhash']))     { $normalized_transaction_event['bitcoinTx']['blockhash']     = $raw_transaction_event['blockhash']; }
        if (isset($raw_transaction_event['blockId']))       { $normalized_transaction_event['bitcoinTx']['blockheight']   = $raw_transaction_event['blockId']; }

        // timestamp
        if (isset($raw_transaction_event['timestamp'])) {
            $normalized_transaction_event['timestamp'] = $raw_transaction_event['timestamp'];
            $normalized_transaction_event['bitcoinTx']['timestamp'] = $raw_transaction_event['timestamp'];
        }

        // vins
        if (isset($raw_transaction_event['vins'])) {
            foreach ($raw_transaction_event['vins'] as $offset => $vin) {
                $normalized_transaction_event['bitcoinTx']['vin'][$offset] = array_replace_recursive($normalized_transaction_event['bitcoinTx']['vin'][$offset], $vin);
            }
        }


        // timing
        if (isset($raw_transaction_event['mempool'])) {}
        if (isset($raw_transaction_event['blockId'])) {}


        // apply special transaction
        $normalized_transaction_event['txid'] = str_replace('%%last_send_txid%%', $this->last_send_txid, $normalized_transaction_event['txid']);
        

        // echo "\$normalized_transaction_event: ".json_encode($normalized_transaction_event, 192)."\n";
        return $normalized_transaction_event;
    }

    protected function processTransactionEvent($transaction_event) {
        // run the job
        // echo "\$transaction_event:\n".json_encode($transaction_event, 192)."\n";

        $confirmations = (isset($transaction_event['bitcoinTx']['confirmations']) ? $transaction_event['bitcoinTx']['confirmations'] : 0);

        // if this is a confirmed transaction event, then we must supply a block
        if ($confirmations > 0) {
            $block_hash = $transaction_event['bitcoinTx']['blockhash'];
            $block = $this->block_repository->findByHash($block_hash);
            if (!$block) {
                // Log::debug("creating sample block $block_hash");
                $block = $this->sample_block_helper->createSampleBlock('default_parsed_block_01.json', [
                    'hash' => $block_hash,
                ]);
            }

            $block_seq = 0;
            $this->events->fire('xchain.tx.confirmed', [$transaction_event, $confirmations, $block_seq, $block]);

        } else {
            // unconfirmed tx
            $this->events->fire('xchain.tx.received', [$transaction_event, 0, null, null, ]);
        }
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

        if (isset($normalized_block_event['tx'])) {
            $filtered_tx = [];
            foreach($normalized_block_event['tx'] as $raw_tx) {
                $filtered_tx[] = str_replace('%%last_send_txid%%', $this->last_send_txid, $raw_tx);
            }
            $normalized_block_event['tx'] = $filtered_tx;
        }

        // echo "\$normalized_block_event:\n".json_encode($normalized_block_event, 192)."\n";
        return $normalized_block_event;
    }

    protected function processBlockEvent($block_event) {
        // run the job
        $this->events->fire('xchain.block.received', [$block_event]);
    }


    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // clear blocks

    protected function clearDatabasesForScenario() {
        \App\Models\Block::truncate();
        \App\Models\Transaction::truncate();
        DB::table('transaction_address_lookup')->truncate();
        \App\Models\Notification::truncate();
        \App\Models\MonitoredAddress::truncate();
        \App\Models\EventMonitor::truncate();
        \App\Models\PaymentAddress::truncate();
        \App\Models\Send::truncate();
        \App\Models\User::truncate();
        \App\Models\Account::truncate();
        \App\Models\APICall::truncate();
        \App\Models\LedgerEntry::truncate();

        return;
    }
    
    


    ////////////////////////////////////////////////////////////////////////
    // Validate Accounts

    protected function validateAccounts($accounts, $meta) {
        $actual_accounts = $this->getActualAccounts();
        
        foreach ($accounts as $offset => $raw_expected_account) {
            // get actual account
            $actual_account = isset($actual_accounts[$offset]) ? $actual_accounts[$offset] : null;

            // normalize expected account
            $expected_account = $this->normalizeExpectedAccount($raw_expected_account, $actual_account);

            $this->validateAccount($expected_account, $actual_account);
        }

        // check extra accounts
        $allow_extra_accounts = isset($meta['allowExtraAccounts']) ? $meta['allowExtraAccounts'] : false;
        if (!$allow_extra_accounts) {
            if (count($actual_accounts) > count($accounts)) {
                throw new Exception("Found ".count($actual_accounts)." unexpected extra account(s): ".substr(rtrim(json_encode($actual_accounts, 192)), 0, 400), 1);
            }
        }
    }

    protected function getActualAccounts() {
        $actual_accounts = app('App\Repositories\AccountRepository')->findAll();
        $actual_accounts_out = [];
        $repo = app('App\Repositories\LedgerEntryRepository');
        foreach($actual_accounts as $actual_account) {
            $balances = $repo->accountBalancesByAsset($actual_account, null);
            $actual_account['balances'] = $balances;
            $actual_accounts_out[] = $actual_account;
        }
        return $actual_accounts_out;
    }


    protected function validateAccount($expected_account, $actual_account) {
        PHPUnit::assertNotEmpty($actual_account, "Missing account ".json_encode($expected_account, 192));
        PHPUnit::assertEquals($expected_account, $actual_account->toArray(), "Account mismatch.  Actual account: ".json_encode($actual_account, 192));
    }


    protected function resolveExpectedAccountMeta($raw_expected_account) {
        // get meta
        $meta = [];
        if (isset($raw_expected_account['meta'])) {
            $meta = $raw_expected_account['meta'];
            unset($raw_expected_account['meta']);
        }

        // load from baseFilename
        if (isset($meta['baseFilename'])) {
            $sample_filename = $meta['baseFilename'];

            $default = Yaml::parse(file_get_contents(base_path().'/tests/fixtures/accounts/'.$sample_filename));
            $expected_account = array_replace_recursive($default, $raw_expected_account);
        } else {
            $expected_account = $raw_expected_account;
        }

        return [$expected_account, $meta];
    }


    protected function normalizeExpectedAccount($raw_expected_account, $raw_actual_account) {
        if (!$raw_actual_account) { return null; }
        list($expected_account, $meta) = $this->resolveExpectedAccountMeta($raw_expected_account);
        $actual_account = $raw_actual_account->toArray();

        $normalized_expected_account = [];

        ///////////////////
        // EXPECTED
        foreach (['name','balances',] as $field) {
            if (isset($expected_account[$field])) { $normalized_expected_account[$field] = $expected_account[$field]; }
        }
        ///////////////////

        ///////////////////
        // OPTIONAL
        foreach (['id','uuid','active','meta','payment_address_id','user_id',] as $field) {
            if (array_key_exists($field, $expected_account)) {
                if (is_array($expected_account[$field])) {
                    $normalized_expected_account[$field] = array_replace_recursive(isset($actual_account[$field]) ? $actual_account[$field] : [], $expected_account[$field]);
                } else {
                    $normalized_expected_account[$field] = $expected_account[$field];
                }
            } else if (array_key_exists($field, $actual_account)) {
                $normalized_expected_account[$field] = $actual_account[$field];
            }
        }
        ///////////////////

        ///////////////////
        // Never
        foreach (['created_at','updated_at',] as $field) {
            $normalized_expected_account[$field] = "".$actual_account[$field];
        }
        ///////////////////



        return $normalized_expected_account;
    }


    ////////////////////////////////////////////////////////////////////////
    // Transfer

    protected function processTransferEvent($event) {
        // type: transfer
        // from: default
        // to:  account1
        // quantity: 300
        // asset: LTBCOIN
        // transferType: unconfirmed
        // txid: cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741

        // get the account (first one)
        // $account_repository = app('App\Repositories\AccountRepository');
        $account = app('App\Repositories\AccountRepository')->findAll()->first();
        $payment_address = app('App\Repositories\PaymentAddressRepository')->findById($account['payment_address_id']);
        $user = app('App\Repositories\UserRepository')->findById($account['user_id']);

        $txid = isset($event['txid']) ? $event['txid'] : null;
        $api_call = app('APICallHelper')->newSampleAPICall($user);

        if (isset($event['close']) AND $event['close']) {
            AccountHandler::close($payment_address, $event['from'], $event['to'], $api_call);

        } else {
            AccountHandler::transfer($payment_address, $event['from'], $event['to'], $event['quantity'], $event['asset'], $txid, $api_call);
        }
    }


    ////////////////////////////////////////////////////////////////////////
    // Send

    protected function processSendEvent($event) {
        // type: send
        // destination: RECIPIENT01
        // asset: BTC
        // quantity: 0.1
        // account: sendingaccount1
        $vars = $event;

        unset($vars['type']);

        // get the first payment address
        $payment_address = app('App\Repositories\PaymentAddressRepository')->findAll()->first();

        $api_test_helper = app('APITestHelper')->useUserHelper(app('UserHelper'))->setURLBase('/api/v1/sends/');
        $send_response = $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$payment_address['uuid'], app('SampleSendsHelper')->samplePostVars($vars));
        Log::debug("\$send_response=".json_encode($send_response, 192));
        $this->last_send_txid = $send_response['txid'];
    }
}