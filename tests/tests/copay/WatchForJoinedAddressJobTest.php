<?php

use App\Models\TXO;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use \PHPUnit_Framework_Assert as PHPUnit;

class WatchForJoinedAddressJobTest extends TestCase {

    protected $useDatabase = true;

    public function testWatchForJoinedAddressJobTest()
    {
        $notification_helper = app('NotificationHelper')->recordNotifications();
        $monitored_address_repository = app('App\Repositories\MonitoredAddressRepository');
        $payment_address_repository = app('App\Repositories\PaymentAddressRepository');

        $user = app('UserHelper')->getSampleUser();

        // install mocks
        $mock_copay_client = app('CopayClientMockHelper')->mockCopayClient();
        $mock_copay_client->shouldReceive('getWallet')->once()->andReturn(['wallet' => ['status' => 'complete']]);
        $mock_copay_client->shouldReceive('getAddressInfo')->once()->andReturn(['address' => '3AAAA1111xxxxxxxxxxxxxxxxxxy3SsDsZ']);

        // create addresses
        $multisig_address_1 = app('PaymentAddressHelper')->createSampleMultisigPaymentAddress();
        $multisig_address_2 = app('PaymentAddressHelper')->createSampleMultisigPaymentAddress();

        // create join notifications
        $monitor_vars = [
            'user_id'            => $user['id'],
            'address'            => '',
            'payment_address_id' => $multisig_address_1['id'],
            'webhookEndpoint'    => 'http://foo.bar/_callback',
            'monitorType'        => 'joined',
            'active'             => true,
        ];
        $joined_monitor_1 = $monitored_address_repository->create([] + $monitor_vars);
        $joined_monitor_2 = $monitored_address_repository->create(['payment_address_id' => $multisig_address_2['id']] + $monitor_vars);


        // fire the job
        $data = [
            'payment_address_id' => $multisig_address_1['id'],
            'joined_monitor_id'  => $joined_monitor_1['id'],
            'start_time'         => time(),
        ];
        $watch_job = app('App\Jobs\Copay\WatchForJoinedAddressJob');
        $mock_job = new SyncJob(app(), $data);
        $watch_job->fire($mock_job, $data);

        // check that a notification was sent
        $notifications = $notification_helper->getAllNotifications();
        PHPUnit::assertEquals(1, $notification_helper->countNotificationsByEventType($notifications, 'joined'));
        $notification = $notifications[0];
        PHPUnit::assertEquals('joined', $notification['event']);
        PHPUnit::assertEquals('3AAAA1111xxxxxxxxxxxxxxxxxxy3SsDsZ', $notification['address']);
        PHPUnit::assertEquals('3AAAA1111xxxxxxxxxxxxxxxxxxy3SsDsZ', $notification['notifiedAddress']);
        PHPUnit::assertEquals($multisig_address_1['uuid'], $notification['notifiedAddressId']);

        // check that the payment address now has an address
        $reloaded_multisig_address_1 = $payment_address_repository->findById($multisig_address_1['id']);
        PHPUnit::assertEquals('3AAAA1111xxxxxxxxxxxxxxxxxxy3SsDsZ', $reloaded_multisig_address_1['address']);

        // check that the monitor was deleted
        $reloaded_joined_monitor_1 = $monitored_address_repository->findById($joined_monitor_1['id']);
        PHPUnit::assertEmpty($reloaded_joined_monitor_1);

    }


}
