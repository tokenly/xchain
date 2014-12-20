<?php 

namespace App\Handlers\XChain;

use App\Blockchain\Transaction\TransactionStore;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Queue\QueueManager;
use Nc\FayeClient\Client as FayeClient;

class XChainTransactionHandler {

    public function __construct(MonitoredAddressRepository $monitored_address_repository, NotificationRepository $notification_repository, TransactionStore $transaction_store, QueueManager $queue_manager, Log $log) {
        $this->monitored_address_repository = $monitored_address_repository;
        $this->notification_repository      = $notification_repository;
        $this->transaction_store            = $transaction_store;
        $this->queue_manager                = $queue_manager;
        $this->log                          = $log;
    }

    public function storeParsedTransaction($parsed_tx) {
        // we don't store confirmations
        unset($parsed_tx['bitcoinTx']['confirmations']);

        $transaction = $this->transaction_store->storeParsedTransaction($parsed_tx);
        return;
    }

    public function sendNotifications($parsed_tx, $confirmations)
    {
        // echo "\$parsed_tx:\n".json_encode($parsed_tx, 192)."\n";
        $this->wlog('sendNotifications txid: '.$parsed_tx['txid'].' $confirmations: '.$confirmations);

        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

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

            $notification = [
                'event'            => $event_type,
                'notificationId'   => null,
                'notifiedAddress'  => $found_address['address'],

                'txid'             => $parsed_tx['txid'],
                'isCounterpartyTx' => $parsed_tx['isCounterpartyTx'],
                'quantity'         => $parsed_tx['quantity'],
                'quantitySat'      => $parsed_tx['quantitySat'],
                'asset'            => $parsed_tx['asset'],

                'sources'          => ($parsed_tx['sources'] ? $parsed_tx['sources'] : []),
                'destinations'     => ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []),

                'counterpartyTx'   => $parsed_tx['counterpartyTx'],
                'bitcoinTx'        => $parsed_tx['bitcoinTx'],

                // ISO 8601
                'transactionTime'  => $this->getISO8601Timestamp($parsed_tx['timestamp']),
                'confirmations'    => $confirmations,
                'confirmed'        => ($confirmations > 0 ? true : false),
            ];


            // create a notification
            $notification_vars_for_model = $notification;
            unset($notification_vars_for_model['notificationId']);
            $notification_model = $this->notification_repository->createForMonitoredAddress(
                $found_address,
                [
                    'txid'          => $parsed_tx['txid'],
                    'confirmations' => $confirmations,
                    'notification'  => $notification_vars_for_model,
                ]
            );
            
            $api_key = '[none]';
            $api_secret = '[secret]';

            $notification['notificationId'] = $notification_model['uuid'];
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
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@storeParsedTransaction');
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@sendNotifications');
        $events->listen('xchain.tx.confirmed', 'App\Handlers\XChain\XChainTransactionHandler@sendNotifications');
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
