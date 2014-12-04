<?php 

namespace App\Handlers\XChain;

use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Queue\QueueManager;
use Nc\FayeClient\Client as FayeClient;

class XChainNotificationBuilderHandler {

    public function __construct(MonitoredAddressRepository $monitored_address_repository, NotificationRepository $notification_repository, QueueManager $queue_manager, Log $log) {
        $this->monitored_address_repository = $monitored_address_repository;
        $this->notification_repository      = $notification_repository;
        $this->queue_manager                = $queue_manager;
        $this->log                          = $log;
    }

    public function pushEvent($tx_event)
    {
        $sources = ($tx_event['sources'] ? $tx_event['sources'] : []);
        $destinations = ($tx_event['destinations'] ? $tx_event['destinations'] : []);

        // get all addresses that we care about
        $found_addresses = $this->monitored_address_repository->findByAddresses(array_unique(array_merge($sources, $destinations)));
        if (!$found_addresses->count()) { return; }

        $this->wlog("begin loop");
        foreach($found_addresses->get() as $found_address) {
            $this->wlog("\$found_address=$found_address");

            // see if this is a receiving or sending event
            $event_type = null;
            switch ($found_address['monitor_type']) {
                case 'send': $event_type = 'send'; break;
                case 'receive': $event_type = 'receive'; break;
            }

            // create a notification
            $notification_model = $this->notification_repository->create($found_address);
            
            $api_key = '[none]';
            $api_secret = '[secret]';

            $notification = [
                'event'            => $event_type,
                'notificationId'   => $notification_model['uuid'],
                'notifiedAddress'  => $found_address['address'],

                'txid'             => $tx_event['txid'],
                'isCounterpartyTx' => $tx_event['isCounterpartyTx'],
                'quantity'         => $tx_event['quantity'],
                'quantitySat'      => $tx_event['quantitySat'],
                'asset'            => $tx_event['asset'],

                'sources'          => ($tx_event['sources'] ? $tx_event['sources'] : []),
                'destinations'     => ($tx_event['destinations'] ? $tx_event['destinations'] : []),

                'counterpartyTx'   => $tx_event['counterpartyTx'],
                'bitcoinTx'        => $tx_event['bitcoinTx'],

                // ISO 8601
                'transactionTime'  => $this->getISO8601Timestamp($tx_event['timestamp']),
            ];

            $notification_json = json_encode($notification);

            $signature = hash_hmac('sha256', $notification_json, $api_secret, false);

            $notification_entry = [
                'meta' => [
                    'id'        => $notification_model['uuid'],
                    'endpoint'  => $found_address['webhookEndpoint'],
                    'timestamp' => time(),
                    'apiKey'    => $api_key,
                    'signature' => $signature,
                    'attempt'   => 0,
                ],

                'payload' => $notification_json,
            ];
            // echo "\$notification_entry:\n".json_encode($notification_entry, 192)."\n";

            // put notification in the queue
            // Queue::push('notification', $notification, 'notifications_out');
            // Queue::pushRaw(['job' => 'notification', 'data' => $notification], 'notifications_out');
            // $this->queue_manager->connection('notifications_out')->push('notification', $notification);
            $this->queue_manager
                ->connection('notifications_out')
                ->pushRaw(json_encode($notification_entry), 'notifications_out');
        }

    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainNotificationBuilderHandler@pushEvent');
    }

    protected function wlog($text) {
        $this->log->info($text);
    }

    protected function getISO8601Timestamp($timestamp=null) {
        $_t = new \DateTime('now');
        if ($timestamp !== null) {
            $_t->setTimestamp($timestamp);
        }
        $_t->setTimezone(new \DateTimeZone('UTC'));
        return $_t->format(\DateTime::ISO8601);
    }

}
