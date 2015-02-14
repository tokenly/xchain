<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore;
use App\Handlers\XChain\Network\Contracts\NetworkTransactionHandler;
use Tokenly\LaravelEventLog\Facade\EventLog;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Util\DateTimeUtil;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Queue\QueueManager;
use Tokenly\CurrencyLib\CurrencyUtil;

class BitcoinTransactionHandler implements NetworkTransactionHandler {

    public function __construct(MonitoredAddressRepository $monitored_address_repository, UserRepository $user_repository, NotificationRepository $notification_repository, BitcoinTransactionStore $transaction_store, QueueManager $queue_manager, Log $log) {
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
        $block_seq = null;
        $transaction = $this->transaction_store->storeParsedTransaction($parsed_tx, $block_seq);
        return;
    }

    public function sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time)
    {
        // echo "\$parsed_tx:\n".json_encode($parsed_tx, 192)."\n";
        // $this->wlog('sendNotifications txid: '.$parsed_tx['txid'].' $confirmations: '.$confirmations);

        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // get all addresses that we care about
        $all_addresses = array_unique(array_merge($sources, $destinations));
        if ($all_addresses) {
            $monitored_addresses = $this->monitored_address_repository->findByAddresses($all_addresses);
        }
        if (!$all_addresses OR !$monitored_addresses->count()) { return; }

        $this->wlog("begin loop");
        foreach($monitored_addresses->get() as $monitored_address) {
            // see if this is a receiving or sending event
            $event_type = null;
            switch ($monitored_address['monitor_type']) {
                case 'send': $event_type = 'send'; break;
                case 'receive': $event_type = 'receive'; break;
            }

            // filter this out if it is not a send
            if ($event_type == 'send' AND !in_array($monitored_address['address'], $sources)) {
                // did not match this address
                continue;
            }

            // filter this out if it is not a receive event
            if ($event_type == 'receive' AND !in_array($monitored_address['address'], $destinations)) {
                // did not match this address
                continue;
            }

            $this->wlog("\$monitored_address={$monitored_address['uuid']}");

            // calculate the quantity
            //   for BTC transactions, this is different than the total BTC sent
            $quantity = $this->buildQuantityForEventType($event_type, $parsed_tx, $monitored_address['address']);

            // build the notification
            $notification = $this->buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block_confirmation_time, $block_seq, $monitored_address);

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

            // put notification in the queue
            EventLog::log('notification.out', ['event'=>$notification['event'], 'asset'=>$notification['asset'], 'quantity'=>$notification['quantity'], 'sources'=>$notification['sources'], 'destinations'=>$notification['destinations'], 'endpoint'=>$user['webhook_endpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);
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

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    
    protected function buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block_confirmation_time, $block_seq, $monitored_address) {
        $notification = [
            'event'             => $event_type,

            'network'           => $parsed_tx['network'],
            'asset'             => $parsed_tx['asset'],
            'quantity'          => $quantity,
            'quantitySat'       => CurrencyUtil::valueToSatoshis($quantity),

            'sources'           => $sources,
            'destinations'      => $destinations,

            'notificationId'    => null,
            'txid'              => $parsed_tx['txid'],
            // ISO 8601
            'transactionTime'   => DateTimeUtil::ISO8601Date($parsed_tx['timestamp']),
            'confirmed'         => ($confirmations > 0 ? true : false),
            'confirmations'     => $confirmations,
            'confirmationTime'  => $block_confirmation_time ? DateTimeUtil::ISO8601Date($block_confirmation_time) : '',
            'blockSeq'          => $block_seq,

            'notifiedAddress'   => $monitored_address['address'],
            'notifiedAddressId' => $monitored_address['uuid'],

            // 'counterpartyTx'    => $parsed_tx['counterpartyTx'],
            'bitcoinTx'         => $parsed_tx['bitcoinTx'],
        ];

        return $notification;
    }
    

    protected function wlog($text) {
        $this->log->info($text);
    }

    protected function buildQuantityForEventType($event_type, $parsed_tx, $bitcoin_address) {
        $quantity = 0;

        if ($event_type == 'send') {
            // get total sent by
            //   calculating total of all send values (this doesn't include change)
            foreach ($parsed_tx['values'] as $dest_address => $value) {
                $quantity += $value;
            }
        } else if ($event_type == 'receive') {
            // get the receive value only for this address
            foreach ($parsed_tx['values'] as $dest_address => $value) {
                if ($dest_address == $bitcoin_address) {
                    $quantity += $value;
                }
            }
        }

        return $quantity;
    }

    protected function userByID($id) {
        if (!isset($this->user_by_id)) { $this->user_by_id = []; }
        if (!isset($this->user_by_id[$id])) {
            $this->user_by_id[$id] = $this->user_repository->findByID($id);
        }
        return $this->user_by_id[$id];
    }

}
