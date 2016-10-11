<?php

namespace App\Jobs\Copay;

use App\Models\MonitoredAddress;
use App\Models\PaymentAddress;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\UserRepository;
use App\Util\DateTimeUtil;
use Exception;
use Illuminate\Support\Facades\Log;
use Tokenly\CopayClient\CopayClient;
use Tokenly\CopayClient\CopayWallet;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XcallerClient\Client;

/*
* WatchForJoinedAddressJob
* Pings Copay to see if the address has joined yet
*/
class WatchForJoinedAddressJob
{

    public function __construct(MonitoredAddressRepository $monitored_address_repository, PaymentAddressRepository $payment_address_repository, CopayClient $copay_client, Client $xcaller_client, UserRepository $user_repository, NotificationRepository $notification_repository)
    {
        $this->monitored_address_repository = $monitored_address_repository;
        $this->payment_address_repository   = $payment_address_repository;
        $this->copay_client                 = $copay_client;
        $this->xcaller_client               = $xcaller_client;
        $this->user_repository              = $user_repository;
        $this->notification_repository      = $notification_repository;
    }

    public function fire($job, $data)
    {

        try {
            // attempt job
            $success = $this->fireJob($job, $data);

            if ($success) {
                // job successfully handled
                $job->delete();
                return;            
            }

        } catch (Exception $e) {
            EventLog::logError('job.failed', $e, $data);
        }


        // not done yet
        $time_running = time() - $data['start_time'];
        if ($time_running > (86400 * 3)) {
            // more than 3 days
            $release_time = 900; // 15 minutes
        } else if ($time_running > 86400) {
            // more than 24 hours
            $release_time = 300; // 5 minutes
        } else if ($time_running > 3600) {
            // more than 1 hour
            $release_time = 60; // 1 minute
        } else if ($time_running > 300) {
            // more than 5 minutes
            $release_time = 30; // 30 seconds
        } else {
            // less than 5 minutes
            $release_time = 5; // 5 seconds
        }

        $job->release($release_time);

            
    }

    // [
    //     payment_address_id
    //     joined_monitor_id
    //     start_time
    // ]
    public function fireJob($job, $data) {
        $complete = false;

        // load the payment address
        $payment_address = $this->payment_address_repository->findbyId($data['payment_address_id']);
        if (!$payment_address) {
            // the payment address has been deleted
            //   just end the job
            return true;
        }

        // get the wallet info
        $wallet = CopayWallet::newWalletFromPlatformSeedAndWalletSeed(env('BITCOIN_MASTER_KEY'), $payment_address['private_key_token']);
        // Log::debug("\$data['payment_address_id']={$data['payment_address_id']} copayerId=".json_encode($wallet['copayerId'], 192));
        $wallet_info = $this->copay_client->getWallet($wallet);
        if ($wallet_info['wallet']['status'] == 'complete') {
            // get the address
            $address_info = $this->copay_client->getAddressInfo($wallet);
            $address = $address_info['address'];

            // update the address in the database
            $this->payment_address_repository->update($payment_address, [
                'address'      => $address,
                'copay_status' => PaymentAddress::COPAY_STATUS_COMPLETE,
            ]);

            $monitor = $this->monitored_address_repository->findById($data['joined_monitor_id']);
            if (!$monitor) {
                // no monitor found - just update the address
                return true;
            }

            // send the notification
            $this->sendJoinedNotification($monitor, $payment_address);

            // delete the monitor
            if ($monitor) {
                // archive all notifications first
                $this->notification_repository->findByMonitoredAddressId($monitor['id'])->each(function($notification) {
                    $this->notification_repository->archive($notification);
                });

                $this->monitored_address_repository->delete($monitor);
            }

            // return complete to delete the notification job
            $complete = true;
        }

        return $complete;
    }


    public function sendJoinedNotification(MonitoredAddress $monitor, PaymentAddress $payment_address) {
        $event_type = 'joined';

        $notification = [
            'notificationId'    => null,
            'event'             => $event_type,
            'address'           => $payment_address['address'],
            'joinedTime'        => DateTimeUtil::ISO8601Date(),
            'notifiedAddress'   => $payment_address['address'],
            'notifiedAddressId' => $payment_address['uuid'],
        ];

        try {

            $notification_vars_for_model = $notification;
            unset($notification_vars_for_model['notificationId']);

            // Log::debug("creating notification: ".json_encode(['txid' => $parsed_tx['txid'], 'confirmations' => $confirmations, 'block_id' => $block ? $block['id'] : null,], 192));
            $create_vars = [
                'txid'          => '',
                'confirmations' => 0,
                'notification'  => $notification_vars_for_model,
                'event_type'    => $event_type,
            ];
            $notification_model = $this->notification_repository->createForMonitoredAddress($monitor, $create_vars);
        } catch (QueryException $e) {
            if ($e->errorInfo[0] == 23000) {
                EventLog::logError('notification.duplicate.error', $e, ['txid' => $txid, 'monitored_address_id' => $monitor['id'], 'event_type' => $event_type]);
                return;
            } else {
                throw $e;
            }
        }

        // get the user
        $user = $this->user_repository->findById($monitor['user_id']);

        // update notification
        $notification['notificationId'] = $notification_model['uuid'];

        // put notification in the queue
        EventLog::log('notification.out', [
            'event'    => $notification['event'],
            'address'  => isset($notification['notifiedAddress']) ? $notification['notifiedAddress'] : null,
            'endpoint' => $monitor['webhookEndpoint'],
            'user'     => $user['id'],
            'id'       => $notification_model['uuid']
        ]);
        $this->xcaller_client->sendWebhook($notification, $monitor['webhookEndpoint'], $notification_model['uuid'], $user['apitoken'], $user['apisecretkey']);

        return true;
    }
}
