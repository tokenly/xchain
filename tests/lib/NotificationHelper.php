<?php

use App\Models\Block;
use App\Repositories\NotificationRepository;
use Illuminate\Queue\QueueManager;

/**
*  NotificationHelper
*/
class NotificationHelper
{

    function __construct(NotificationRepository $notification_repository, \MonitoredAddressHelper $monitored_address_helper, QueueManager $queue_manager) {
        $this->notification_repository  = $notification_repository;
        $this->monitored_address_helper = $monitored_address_helper;
        $this->queue_manager            = $queue_manager;
    }


    public function createSampleNotification($address_model=null, $override_vars=[]) {
        if ($address_model === null) {
            $address_model = $this->monitored_address_helper->createSampleMonitoredAddress();
        }

        return $this->notification_repository->createForMonitoredAddress($address_model, array_merge($this->sampleVars(), $override_vars));
    }

    public function sampleVars($override_vars=[], Block $block=null) {
        if ($block == null) {
            $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');
        }

        return array_merge([
            'confirmations' => 0,
            'txid'          => 'cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741',
            'notification'  => ['foo' => 'bar'],
            'block_id'      => $block['id'],
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return $this->sampleVars($override_vars);
    }

    public function recordNotifications() {
        $this->queue_manager->addConnector('sync', function() {
            return new \TestMemorySyncConnector();
        });

        // clear the queue
        $this->getAllNotifications();

        return $this;
    }

    public function getAllNotifications() {
        $all_notifications = [];

        while (true) {
            $raw_queue_entry = $this->queue_manager->connection('notifications_out')->pop();
            if (!$raw_queue_entry) { break; }

            $raw_notification_entry = json_decode($raw_queue_entry, true);
            if (!$raw_notification_entry) { throw new Exception("Empty notification found", 1); }

            $notification = json_decode($raw_notification_entry['payload'], true);
            $all_notifications[] = $notification;
        }

        return $all_notifications;
    }

    public function countNotificationsByEventType($notifications, $event) {
        $count = 0;
        foreach($notifications as $notification) {
            if ($notification['event'] == $event) {
                ++$count;
            }
        }
        return $count;
    }

}