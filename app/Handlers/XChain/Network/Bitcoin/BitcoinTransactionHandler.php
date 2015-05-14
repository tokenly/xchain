<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore;
use App\Handlers\XChain\Network\Contracts\NetworkTransactionHandler;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Util\DateTimeUtil;
use Illuminate\Contracts\Logging\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XcallerClient\Client;

class BitcoinTransactionHandler implements NetworkTransactionHandler {

    public function __construct(MonitoredAddressRepository $monitored_address_repository, UserRepository $user_repository, NotificationRepository $notification_repository, BitcoinTransactionStore $transaction_store, Client $xcaller_client, Log $log) {
        $this->monitored_address_repository = $monitored_address_repository;
        $this->user_repository              = $user_repository;
        $this->notification_repository      = $notification_repository;
        $this->transaction_store            = $transaction_store;
        $this->xcaller_client               = $xcaller_client;
        $this->log                          = $log;
    }

    public function storeParsedTransaction($parsed_tx) {
        // we don't store confirmations
        unset($parsed_tx['bitcoinTx']['confirmations']);
        $block_seq = null;
        $transaction = $this->transaction_store->storeParsedTransaction($parsed_tx, $block_seq);
        return;
    }

    public function handleConfirmedTransaction($parsed_tx, $confirmations, $block_seq, $block_confirmation_time) {
        // with bitcoin, we assume the confirmed transaction is valid
        return $this->sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time);
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
            // find all monitored address matching those in the sources or destinations
            //  inactive monitored address are ignored
            $monitored_addresses = $this->monitored_address_repository->findByAddresses($all_addresses, true);
        }
        if (!$all_addresses OR !$monitored_addresses->count()) { return; }

        // determine matched monitored addresses
        $matched_monitored_address_ids = [];
        $this->wlog("begin matching addresses");
        foreach($monitored_addresses->get() as $monitored_address) {
            // see if this is a receiving or sending event
            //   (send, receive)
            $event_type = $monitored_address['monitor_type'];

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

            $matched_monitored_address_ids[] = $monitored_address['uuid'];
        }

        if (!$this->preprocessSendNotification($parsed_tx, $confirmations, $block_seq, $block_confirmation_time, $matched_monitored_address_ids)) { return; }

        $this->sendNotificationsForMatchedMonitorIDs($parsed_tx, $confirmations, $block_seq, $block_confirmation_time, $matched_monitored_address_ids);
    }


    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    

    protected function sendNotificationsForMatchedMonitorIDs($parsed_tx, $confirmations, $block_seq, $block_confirmation_time, $matched_monitored_address_ids) {
        $this->wlog("begin loop");

        // build sources and destinations
        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // loop through all matched monitored addresses
        foreach($matched_monitored_address_ids as $monitored_address_uuid) {
            $monitored_address = $this->monitored_address_repository->findByUuid($monitored_address_uuid);
            if (!$monitored_address) { continue; }

            $event_type = $monitored_address['monitor_type'];
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

            // update notification
            $notification['notificationId'] = $notification_model['uuid'];

            // put notification in the queue
            EventLog::log('notification.out', ['event'=>$notification['event'], 'asset'=>$notification['asset'], 'quantity'=>$notification['quantity'], 'sources'=>$notification['sources'], 'destinations'=>$notification['destinations'], 'endpoint'=>$user['webhook_endpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);

            $this->xcaller_client->sendWebhook($notification, $monitored_address['webhookEndpoint'], $notification_model['uuid'], $user['apitoken'], $user['apisecretkey']);
        }

    }

    // if this returns false, don't send the notification
    protected function preprocessSendNotification($parsed_tx, $confirmations, $block_seq, $block_confirmation_time, $matched_monitored_address_ids) {
        // for bitcoin, always send confirmed notifications
        //   because bitcoind has already validated the confirmed transaction
        return true;
    }

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
