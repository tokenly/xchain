<?php 

namespace App\Handlers\XChain;

use App\Blockchain\Transaction\TransactionStore;
use App\Providers\EventLog\Facade\EventLog;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Queue\QueueManager;

class XChainTransactionHandler {

    public function __construct(MonitoredAddressRepository $monitored_address_repository, UserRepository $user_repository, NotificationRepository $notification_repository, TransactionStore $transaction_store, QueueManager $queue_manager, Log $log) {
        $this->monitored_address_repository = $monitored_address_repository;
        $this->user_repository              = $user_repository;
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

    public function sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time)
    {
        // echo "\$parsed_tx:\n".json_encode($parsed_tx, 192)."\n";
        // $this->wlog('sendNotifications txid: '.$parsed_tx['txid'].' $confirmations: '.$confirmations);

        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // get all addresses that we care about
        $monitored_addresses = $this->monitored_address_repository->findByAddresses(array_unique(array_merge($sources, $destinations)));
        if (!$monitored_addresses->count()) { return; }

        $this->wlog("begin loop");
        foreach($monitored_addresses->get() as $monitored_address) {
            $this->wlog("\$monitored_address=$monitored_address");

            // see if this is a receiving or sending event
            $event_type = null;
            switch ($monitored_address['monitor_type']) {
                case 'send': $event_type = 'send'; break;
                case 'receive': $event_type = 'receive'; break;
            }

            $notification = [
                'event'             => $event_type,
                'notificationId'    => null,
                'notifiedAddress'   => $monitored_address['address'],
                'notifiedAddressId' => $monitored_address['uuid'],

                'txid'              => $parsed_tx['txid'],
                'isCounterpartyTx'  => $parsed_tx['isCounterpartyTx'],
                'quantity'          => $parsed_tx['quantity'],
                'quantitySat'       => $parsed_tx['quantitySat'],
                'asset'             => $parsed_tx['asset'],

                'sources'           => ($parsed_tx['sources'] ? $parsed_tx['sources'] : []),
                'destinations'      => ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []),

                'counterpartyTx'    => $parsed_tx['counterpartyTx'],
                'bitcoinTx'         => $parsed_tx['bitcoinTx'],

                // ISO 8601
                'transactionTime'   => $this->getISO8601Timestamp($parsed_tx['timestamp']),
                'confirmations'     => $confirmations,
                'confirmationTime'  => $this->getISO8601Timestamp($block_confirmation_time),
                'confirmed'         => ($confirmations > 0 ? true : false),
                'blockSeq'          => $block_seq,
            ];

            $this->wlog("\$parsed_tx['timestamp']={$parsed_tx['timestamp']} transactionTime=".$notification['transactionTime']);


            // create a notification
            $notification_vars_for_model = $notification;
            unset($notification_vars_for_model['notificationId']);
            $notification_model = $this->notification_repository->createForMonitoredAddress(
                $monitored_address,
                [
                    'txid'          => $parsed_tx['txid'],
                    'confirmations' => $confirmations,
                    'notification'  => $notification_vars_for_model,
                ]
            );
            
            // apply user API token and key
            $user = $this->userByID($monitored_address['user_id']);
            $api_token = $user['apitoken'];
            $api_secret = $user['apisecretkey'];

            // update notification
            $notification['notificationId'] = $notification_model['uuid'];
            $notification_json_string = json_encode($notification);

            // sign request
            $signature = hash_hmac('sha256', $notification_json_string, $api_secret, false);

            $notification_entry = [
                'meta' => [
                    'id'        => $notification_model['uuid'],
                    'endpoint'  => $monitored_address['webhookEndpoint'],
                    'timestamp' => time(),
                    'apiToken'  => $api_token,
                    'signature' => $signature,
                    'attempt'   => 0,
                ],

                'payload' => $notification_json_string,
            ];
            // echo "\$notification_entry:\n".json_encode($notification_entry, 192)."\n";

            // put notification in the queue
            // Queue::push('notification', $notification, 'notifications_out');
            // Queue::pushRaw(['job' => 'notification', 'data' => $notification], 'notifications_out');
            // $this->queue_manager->connection('notifications_out')->push('notification', $notification);
            EventLog::log('notification.out', ['event'=>$notification['event'], 'endpoint'=>$user['webhook_endpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);
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
        if ($timestamp === 0) { return ''; }

        $_t = new \DateTime('now');
        if ($timestamp !== null) {
            $_t->setTimestamp($timestamp);
        }
        $_t->setTimezone(new \DateTimeZone('UTC'));
        return $_t->format(\DateTime::ISO8601);
    }

    protected function userByID($id) {
        if (!isset($this->user_by_id)) { $this->user_by_id = []; }
        if (!isset($this->user_by_id[$id])) {
            $this->user_by_id[$id] = $this->user_repository->findByID($id);
        }
        return $this->user_by_id[$id];
    }

}
