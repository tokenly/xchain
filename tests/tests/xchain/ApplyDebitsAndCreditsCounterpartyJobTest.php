<?php

use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use \PHPUnit_Framework_Assert as PHPUnit;

class ApplyDebitsAndCreditsCounterpartyJobTest extends TestCase {

    protected $useDatabase = true;


    public function testApplyDebitsAndCreditsCounterpartyJob() {
        // init mocks
        $mocks = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $notification_helper = app('NotificationHelper')->recordNotifications();

        // sample address
        $user = app('UserHelper')->createSampleUser();
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress($user, ['address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j', 'private_key_token' => '',]);
        $default_account = AccountHandler::getAccount($payment_address);
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccountWithoutUTXOs(['XCP' => 10], $payment_address);

        // create monitors
        $monitored_address_helper = app('MonitoredAddressHelper');
        $receive_monitor = $monitored_address_helper->createSampleMonitoredAddress(null, ['address' => $payment_address['address'], 'monitorType' => 'receive']);
        $send_monitor    = $monitored_address_helper->createSampleMonitoredAddress(null, ['address' => $payment_address['address'], 'monitorType' => 'send']);

        // sample block
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');

        // listen for events
        $heard_events = [];
        Event::listen('xchain.balanceChange.confirmed', function($balance_change_event, $confirmations, $block) use (&$heard_events) {
            $heard_events[] = [$balance_change_event, $confirmations, $block];
        });

        // build and fire the job
        $transaction_helper = app('SampleTransactionsHelper');
        $data = [
            'block_id'     => $block['id'],
            'block_height' => $block['height'],
        ];
        $credits_job = app('App\Jobs\XChain\ApplyDebitsAndCreditsCounterpartyJob');
        $mock_job = new SyncJob(app(), $data);
        $credits_job->fire($mock_job, $data);

        // check for the event
        PHPUnit::assertCount(4, $heard_events);

        // check the balance change event
        $balance_change_event = $heard_events[0][0];
        PHPUnit::assertEquals('1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j', $balance_change_event['address']);
        PHPUnit::assertEquals(2, $balance_change_event['quantity']);
        
        // make sure the address balance was deducted
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger->accountBalancesByAsset($default_account, LedgerEntry::CONFIRMED);
        PHPUnit::assertEquals(8, $balances['XCP']);

        // run the job again for the next block
        $block_2 = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json', ['height' => 333001, 'hash' => '0000000HASH02']);
        $data = [
            'block_id'     => $block_2['id'],
            'block_height' => $block_2['height'],
        ];
        $credits_job = app('App\Jobs\XChain\ApplyDebitsAndCreditsCounterpartyJob');
        $mock_job = new SyncJob(app(), $data);
        $credits_job->fire($mock_job, $data);

        // make sure the address balance was NOT deducted a second time
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger->accountBalancesByAsset($default_account, LedgerEntry::CONFIRMED);
        PHPUnit::assertEquals(8, $balances['XCP']);


        // check the notifications
        $notifications = $notification_helper->getAllNotifications();
        PHPUnit::assertCount(2, $notifications);
        PHPUnit::assertEquals('debit', $notifications[0]['event']);
        PHPUnit::assertEquals('XCP', $notifications[0]['asset']);
        PHPUnit::assertEquals(2, $notifications[0]['quantity']);
        PHPUnit::assertEquals('1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j', $notifications[0]['address']);
        PHPUnit::assertEquals(5, $notifications[0]['confirmations']);

        PHPUnit::assertEquals('debit', $notifications[1]['event']);
        PHPUnit::assertEquals('XCP', $notifications[1]['asset']);
        PHPUnit::assertEquals(2, $notifications[1]['quantity']);
        PHPUnit::assertEquals('1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j', $notifications[1]['address']);
        PHPUnit::assertEquals(6, $notifications[1]['confirmations']);

    }


}
