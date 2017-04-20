<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore;
use App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContextFactory;
use App\Handlers\XChain\Network\Bitcoin\Block\ProvisionalTransactionInvalidationHandler;
use App\Handlers\XChain\Network\Contracts\NetworkTransactionHandler;
use App\Models\Block;
use App\Models\Transaction;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Providers\TXO\Facade\TXOHandler;
use App\Repositories\EventMonitorRepository;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\ProvisionalTransactionRepository;
use App\Repositories\SendRepository;
use App\Repositories\UserRepository;
use App\Util\DateTimeUtil;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XcallerClient\Client;

class BitcoinTransactionHandler implements NetworkTransactionHandler {

    const CONFIRMATIONS_TO_INVALIDATE_PROVISIONAL_TRANSACTIONS = 2;

    public function __construct(MonitoredAddressRepository $monitored_address_repository, EventMonitorRepository $event_monitor_repository, PaymentAddressRepository $payment_address_repository, UserRepository $user_repository, NotificationRepository $notification_repository, SendRepository $send_repository, BitcoinTransactionStore $transaction_store, ProvisionalTransactionRepository $provisional_transaction_repository, Client $xcaller_client, BlockEventContextFactory $block_event_context_factory, ProvisionalTransactionInvalidationHandler $provisional_transaction_invalidation_handler) {
        $this->monitored_address_repository                 = $monitored_address_repository;
        $this->event_monitor_repository                     = $event_monitor_repository;
        $this->payment_address_repository                   = $payment_address_repository;
        $this->provisional_transaction_repository           = $provisional_transaction_repository;
        $this->user_repository                              = $user_repository;
        $this->notification_repository                      = $notification_repository;
        $this->send_repository                              = $send_repository;
        $this->transaction_store                            = $transaction_store;
        $this->xcaller_client                               = $xcaller_client;
        $this->block_event_context_factory                  = $block_event_context_factory;
        $this->provisional_transaction_invalidation_handler = $provisional_transaction_invalidation_handler;
    }

    public function storeParsedTransaction($parsed_tx) {
        // we don't store confirmations
        unset($parsed_tx['bitcoinTx']['confirmations']);
        $block_seq = null;
        $transaction = $this->transaction_store->storeParsedTransaction($parsed_tx, $block_seq);
        return $transaction;
    }

    public function storeProvisionalTransaction(Transaction $transaction, $found_addresses) {
        try {
            if (count($found_addresses['payment_addresses']) > 0 OR count($found_addresses['monitored_addresses']) > 0) {
                return $this->provisional_transaction_repository->create($transaction);
            }
        } catch (QueryException $e) {
            if ($e->errorInfo[0] == 23000) {
                EventLog::logError('provisionalTransaction.duplicate.error', $e, ['id' => $transaction['id'], 'txid' => $transaction['txid'],]);
                return $this->provisional_transaction_repository->findByTXID($transaction['txid']);
            } else {
                throw $e;
            }
        }
    }

    public function newBlockEventContext() {
        $block_event_context = $this->block_event_context_factory->newBlockEventContext();
        return $block_event_context;
    }


    // if this returns true, then pre-processing is necessary and don't send the notificatoin
    public function willNeedToPreprocessNotification($parsed_tx, $confirmations) {
        // bitcoin always sends unconfirmed and confirmed notifications immediately
        return false;
    }

    public function preprocessNotification($parsed_tx, $confirmations, $block_seq, $block) {
        // for bitcoin, always send confirmed notifications
        //   because bitcoind has already validated the confirmed transaction

        // empty for bitcoin
    }



    public function updateProvisionalTransaction($parsed_tx, $found_addresses, $confirmations) {
        if ($confirmations < self::CONFIRMATIONS_TO_INVALIDATE_PROVISIONAL_TRANSACTIONS) { return; }
        try {
            if (count($found_addresses['payment_addresses']) > 0 OR count($found_addresses['monitored_addresses']) > 0) {
                EventLog::log('provisionalTransaction.cleared', ['txid' => $parsed_tx['txid']]);

                // delete the provisional transaction
                $this->provisional_transaction_repository->deleteByTXID($parsed_tx['txid']);
            }
        } catch (QueryException $e) {
            EventLog::logError('provisionalTransaction.update.error', $e, ['id' => $transaction['id'], 'txid' => $transaction['txid'],]);
            throw $e;
        }
    }

    public function invalidateProvisionalTransactions($found_addresses, $parsed_tx, $confirmations, $block_seq, $block, $block_event_context) {
        if ($confirmations < self::CONFIRMATIONS_TO_INVALIDATE_PROVISIONAL_TRANSACTIONS) { return; }

        $invalidated_provisional_transactions = $this->provisional_transaction_invalidation_handler->findInvalidatedTransactions($parsed_tx, $block_event_context['provisional_txids_by_utxo']);
        if ($invalidated_provisional_transactions) {
            foreach($invalidated_provisional_transactions as $invalidated_provisional_transaction) {
                // send notification
                $invalidated_parsed_tx = $invalidated_provisional_transaction->transaction['parsed_tx'];
                $invalidated_found_addresses = $this->findMonitoredAndPaymentAddressesByParsedTransaction($invalidated_parsed_tx);

                $this->sendNotificationsForInvalidatedProvisionalTransaction($invalidated_parsed_tx, $parsed_tx, $invalidated_found_addresses, $confirmations, $block_seq, $block);

                // remove the provisional transactions
                $this->provisional_transaction_repository->delete($invalidated_provisional_transaction);

                // update accounts
                AccountHandler::invalidate($invalidated_provisional_transaction);

                try {
                    TXOHandler::invalidate($invalidated_provisional_transaction);
                } catch (Exception $e) {
                    EventLog::logError('utxo.send.error', $e, ['txid' => $parsed_tx['txid'], 'confirmations' => $confirmations, ]);
                    throw $e;
                }

            }
        }
    }

    public function updateAccountBalances($found_addresses, $parsed_tx, $confirmations, $block_seq, Block $block=null) {
        $sources      = ($parsed_tx['sources']      ? $parsed_tx['sources']      : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // determine matched payment addresses
        foreach($found_addresses['payment_addresses'] as $payment_address) {
            // Log::debug("upating account balances for payment address {$payment_address['address']}.  txid is {$parsed_tx['txid']}");
            
            $is_source      = in_array($payment_address['address'], $sources);
            $is_destination = in_array($payment_address['address'], $destinations);
            $log_data = ['address_id' => $payment_address['id'], 'address' => $payment_address['address'], 'quantity' => 0, 'asset' => $parsed_tx['asset'], 'txid' => $parsed_tx['txid'], ];

            if ($is_source) {
                try {
                    // this address sent something
                    $quantity = $this->buildQuantityForEventType('send', $parsed_tx, $payment_address['address']);
                    $log_data['quantity'] = $quantity;
                    AccountHandler::send($payment_address, $parsed_tx, $confirmations);
                    EventLog::debug('account.updated', array_merge($log_data, ['direction'  => 'send',]));
                } catch (Exception $e) {
                    EventLog::logError('accountUpdate.error', $e, array_merge($log_data, ['direction'  => 'send',]));
                    throw $e;;
                }
            }

            if ($is_destination) {
                try {
                    // this address received something
                    //   Note that the same address might be both the sender and the receiver
                    $quantity = $this->buildQuantityForEventType('receive', $parsed_tx, $payment_address['address']);
                    $log_data['quantity'] = $quantity;
                    AccountHandler::receive($payment_address, $parsed_tx, $confirmations);
                    EventLog::debug('account.updated', array_merge($log_data, ['direction'  => 'receive',]));
                } catch (Exception $e) {
                    EventLog::logError('accountUpdate.error', $e, array_merge($log_data, ['direction'  => 'receive',]));
                    throw $e;;
                }
            }
        }
    }

    public function updateAccountBalancesFromBalanceChangeEvent($found_addresses, $balance_change_event, $confirmations, Block $block=null) {
        foreach($found_addresses['payment_addresses'] as $payment_address) {

            $quantity = $balance_change_event['quantity'];
            $log_data = ['address_id' => $payment_address['id'], 'address' => $payment_address['address'], 'quantity' => $quantity, 'asset' => $balance_change_event['asset'],];

            if ($balance_change_event['event'] == 'debit') {
                try {
                    // this address had assets debited
                    AccountHandler::balanceChangeDebit($payment_address, $quantity, $balance_change_event['asset'], $balance_change_event['fingerprint'], $confirmations);
                    EventLog::debug('account.updated', array_merge($log_data, ['direction'  => 'debit',]));
                } catch (Exception $e) {
                    EventLog::logError('accountUpdate.error', $e, array_merge($log_data, ['direction'  => 'debit',]));
                    throw $e;;
                }
            } else if ($balance_change_event['event'] == 'credit') {
                try {
                    // this address had assets credited
                    AccountHandler::balanceChangeCredit($payment_address, $quantity, $balance_change_event['asset'], $balance_change_event['fingerprint'], $confirmations);
                    EventLog::debug('account.updated', array_merge($log_data, ['direction'  => 'credit',]));
                } catch (Exception $e) {
                    EventLog::logError('accountUpdate.error', $e, array_merge($log_data, ['direction'  => 'credit',]));
                    throw $e;;
                }
            }

        }

    }

    public function sendNotificationsFromBalanceChangeEvent($found_addresses, $balance_change_event, $confirmations, $block) {
        $confirmation_timestamp = $block ? $block['parsed_block']['time'] : null;

        // send notifications to monitored addresses
        if ($found_addresses['matched_monitored_addresses']) {
            // loop through all matched monitored addresses
            foreach($found_addresses['matched_monitored_addresses'] as $monitored_address) {
                // build the notification
                $event_type = $balance_change_event['event'];
                $notification = [
                    'event'                  => $event_type,

                    'network'                => $balance_change_event['network'],
                    'asset'                  => $balance_change_event['asset'],
                    'quantity'               => $balance_change_event['quantity'],
                    'quantitySat'            => CurrencyUtil::valueToSatoshis($balance_change_event['quantity']),

                    'address'                => $balance_change_event['address'],

                    'notificationId'         => null,
                    'transactionTime'        => DateTimeUtil::ISO8601Date($balance_change_event['timestamp']),
                    'confirmed'              => ($confirmations > 0 ? true : false),
                    'confirmations'          => $confirmations,
                    'confirmationTime'       => $confirmation_timestamp ? DateTimeUtil::ISO8601Date($confirmation_timestamp) : '',

                    'notifiedAddress'        => $monitored_address['address'],
                    'notifiedAddressId'      => $monitored_address['uuid'],

                    'counterpartyData'       => $balance_change_event['counterpartyData'],

                    'transactionFingerprint' => isset($balance_change_event['fingerprint']) ? $balance_change_event['fingerprint'] : null,
                ];

                $this->sendNotificationForMonitoredAddress($notification, $monitored_address, $balance_change_event['fingerprint'], $confirmations, $block, $event_type);
            }

        }
    }

    public function updateUTXOs($found_addresses, $parsed_tx, $confirmations, $block_seq, Block $block=null) {
        $sources      = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        // get all destinations, including change addresses
        $destinations = TXOHandler::extractAllDestinationsFromVouts($parsed_tx);


        // determine matched payment addresses
        foreach($found_addresses['payment_addresses'] as $payment_address) {
            // Log::debug("upating account balances for payment address {$payment_address['address']}.  txid is {$parsed_tx['txid']}");

            if (in_array($payment_address['address'], $sources)) {
                // this address sent some UTXOs
                try {
                    TXOHandler::send($payment_address, $parsed_tx, $confirmations);
                } catch (Exception $e) {
                    EventLog::logError('utxo.send.error', $e, ['txid' => $parsed_tx['txid'], 'confirmations' => $confirmations, ]);
                    throw $e;
                }
            }

            if (in_array($payment_address['address'], $destinations)) {
                // this address received some UTXOs
                try {
                    TXOHandler::receive($payment_address, $parsed_tx, $confirmations);
                } catch (Exception $e) {
                    EventLog::logError('utxo.send.error', $e, ['txid' => $parsed_tx['txid'], 'confirmations' => $confirmations, ]);
                    throw $e;
                }
            }

        }
    }


    public function sendNotifications($found_addresses, $parsed_tx, $confirmations, $block_seq, Block $block=null)
    {
        // send notifications to monitored addresses
        if ($found_addresses['matched_monitored_addresses']) {
            $this->sendNotificationsForMatchedMonitorAddresses($parsed_tx, $confirmations, $block_seq, $block, $found_addresses['matched_monitored_addresses']);
        }
    }


    public function findMonitoredAndPaymentAddressesByParsedTransaction($parsed_tx) {
        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // get monitored addresses that we care about
        $all_addresses = array_unique(array_merge($sources, $destinations));
        if ($all_addresses) {
            // find all active monitored address matching those in the sources or destinations
            //  inactive monitored address are ignored
            $monitored_addresses = $this->monitored_address_repository->findByAddresses($all_addresses, true)->get();
        } else {
            // there were no source or destination addresses (?!) in this transaction
            EventLog::logError('transaction.noAddresses', ['txid' => $parsed_tx['txid'],]);
            app('XChainErrorCounter')->incrementErrorCount();
            $monitored_addresses = [];
        }

        // determine the matched monitored addresses based on the monitor type
        $matched_monitored_addresses = [];
        foreach($monitored_addresses as $monitored_address) {
            // see if this is a receiving or sending monitor
            $monitor_type = $monitored_address['monitor_type'];

            // for send monitors, the address must be in the sources
            if ($monitor_type == 'send' AND !in_array($monitored_address['address'], $sources)) {
                continue;
            }

            // for receive monitors, the address must be in the destinations
            if ($monitor_type == 'receive' AND !in_array($monitored_address['address'], $destinations)) {
                continue;
            }

            $matched_monitored_addresses[] = $monitored_address;
        }


        // find all payment addresses matching those in the sources or destinations
        $payment_addresses = $this->payment_address_repository->findByAddresses($all_addresses)->get();

        return [
            'monitored_addresses'         => $monitored_addresses,
            'matched_monitored_addresses' => $matched_monitored_addresses,
            'payment_addresses'           => $payment_addresses,
        ];

    }

    public function findMonitoredAndPaymentAddressesByBalanceChangeEvent($balance_change_event) {
        $address = $balance_change_event['address'];
        $is_send = $balance_change_event['event'] == 'debit';

        // get monitored addresses that we care about
        $monitored_addresses = $this->monitored_address_repository->findByAddress($address)->get();

        // determine the matched monitored addresses based on the monitor type
        $matched_monitored_addresses = $monitored_addresses->filter(function($monitored_address) use ($is_send) {
            if ($monitored_address['monitor_type'] == 'send' AND $is_send) { return true; }
            if ($monitored_address['monitor_type'] == 'receive' AND !$is_send) { return true; }
            return false;
        });

        // find all payment addresses matching those in the sources or destinations
        $payment_addresses = $this->payment_address_repository->findByAddress($address)->get();

        return [
            'monitored_addresses'         => $monitored_addresses,
            'matched_monitored_addresses' => $matched_monitored_addresses,
            'payment_addresses'           => $payment_addresses,
        ];

    }

    // ------------------------------------------------------------------------
    
    public function findEventMonitorsByParsedTransaction($parsed_tx) {
        // get the event type
        $event_type = $this->getEventMonitorType($parsed_tx);
        if (!$event_type) { return []; }

        return $this->event_monitor_repository->findByEventType($event_type);
    }

    public function sendNotificationsToEventMonitors($event_monitors, $parsed_tx, $confirmations, $block_seq, $block) {
        // build sources and destinations
        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // loop through all matched event monitors
        $event_type = $this->getEventMonitorType($parsed_tx);
        foreach($event_monitors as $event_monitor) {
            // calculate the quantity
            //   always treat it as a receive
            $quantity = $this->buildQuantityForEventType('receive', $parsed_tx, $parsed_tx['destinations'] ? $parsed_tx['destinations'][0] : null);

            // build the notification
            $notification = $this->buildNotification('receive', $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, null, $event_monitor);
            if ($notification) {
                $this->sendNotificationForEventMonitor($notification, $event_monitor, $parsed_tx['txid'], $confirmations, $block, $event_type);
            }
        }
    }

    protected function getEventMonitorType($parsed_tx) {
        // abstract
        return null;
    }


    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    

    protected function sendNotificationsForMatchedMonitorAddresses($parsed_tx, $confirmations, $block_seq, $block, $matched_monitored_addresses) {
        // build sources and destinations
        $sources = ($parsed_tx['sources'] ? $parsed_tx['sources'] : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // loop through all matched monitored addresses
        foreach($matched_monitored_addresses as $monitored_address) {
            $event_type = $monitored_address['monitor_type'];

            // calculate the quantity
            //   for BTC transactions, this is different than the total BTC sent
            $quantity = $this->buildQuantityForEventType($event_type, $parsed_tx, $monitored_address['address']);

            // build the notification
            $notification = $this->buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address);
            if ($notification) {
                $this->sendNotificationForMonitoredAddress($notification, $monitored_address, $parsed_tx['txid'], $confirmations, $block, $event_type);
            }
        }

    }

    protected function sendNotificationForMonitoredAddress($notification, $monitored_address, $txid, $confirmations, $block, $event_type) {
        return $this->sendNotification('monitored_address', $notification, $monitored_address, $txid, $confirmations, $block, $event_type);
    }

    protected function sendNotificationForEventMonitor($notification, $event_monitor, $txid, $confirmations, $block, $event_type) {
        return $this->sendNotification('event_monitor', $notification, $event_monitor, $txid, $confirmations, $block, $event_type);
    }

    protected function sendNotification($model_type, $notification, $model, $txid, $confirmations, $block, $event_type) {
        try {
            $notification_vars_for_model = $notification;
            unset($notification_vars_for_model['notificationId']);

            // Log::debug("creating notification: ".json_encode(['txid' => $parsed_tx['txid'], 'confirmations' => $confirmations, 'block_id' => $block ? $block['id'] : null,], 192));
            $create_vars = [
                'txid'          => $txid,
                'confirmations' => $confirmations,
                'notification'  => $notification_vars_for_model,
                'block_id'      => $block ? $block['id'] : null,
                'event_type'    => $event_type,
            ];
            if ($model_type == 'monitored_address') {
                $notification_model = $this->notification_repository->createForMonitoredAddress($model, $create_vars);
            } else if ($model_type == 'event_monitor') {
                $notification_model = $this->notification_repository->createForEventMonitor($model, $create_vars);
            }
        } catch (QueryException $e) {
            if ($e->errorInfo[0] == 23000) {
                EventLog::logError('notification.duplicate.error', $e, ['txid' => $txid, 'monitored_address_id' => $model['id'], 'confirmations' => $confirmations, 'event_type' => $event_type]);
                return;
            } else {
                throw $e;
            }
        }

        // apply user API token and key
        $user = $this->userByID($model['user_id']);

        // update notification
        $notification['notificationId'] = $notification_model['uuid'];

        // put notification in the queue
        // Log::debug("\$notification=".json_encode($notification, 192)." ".format_debug_backtrace(debug_backtrace()));
        EventLog::log('notification.out', ['event'=>$notification['event'], 'txid' => $txid, 'confirmations' => $confirmations, 'asset'=>$notification['asset'], 'quantity'=>$notification['quantity'], 'sources'=>(isset($notification['sources']) ? $notification['sources'] : []), 'destinations'=>(isset($notification['destinations']) ? $notification['destinations'] : []), 'address' => isset($notification['notifiedAddress']) ? $notification['notifiedAddress'] : null, 'endpoint'=>$model['webhookEndpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);
        Log::debug("\$notification=".json_encode($notification, 192));

        $this->xcaller_client->sendWebhook($notification, $model['webhookEndpoint'], $notification_model['uuid'], $user['apitoken'], $user['apisecretkey']);
    }

    protected function buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address, $event_monitor=null) {
        $confirmation_timestamp = $block ? $block['parsed_block']['time'] : null;

        if ($event_type === null) {
            $notification = [
                'network'                => $parsed_tx['network'],
                'asset'                  => $parsed_tx['asset'],

                'sources'                => $sources,
                'destinations'           => $destinations,

                'notificationId'         => null,
                'txid'                   => $parsed_tx['txid'],
                'transactionTime'        => DateTimeUtil::ISO8601Date($parsed_tx['timestamp']),
                'confirmed'              => ($confirmations > 0 ? true : false),
                'confirmations'          => $confirmations,
                'confirmationTime'       => $confirmation_timestamp ? DateTimeUtil::ISO8601Date($confirmation_timestamp) : '',
                'blockSeq'               => $block_seq,

                'bitcoinTx'              => $parsed_tx['bitcoinTx'],

                'transactionFingerprint' => isset($parsed_tx['transactionFingerprint']) ? $parsed_tx['transactionFingerprint'] : null,
            ];
        } else {

            $notification = [
                'event'                  => $event_type,

                'network'                => $parsed_tx['network'],
                'asset'                  => $parsed_tx['asset'],
                'quantity'               => $quantity,
                'quantitySat'            => CurrencyUtil::valueToSatoshis($quantity),

                'sources'                => $sources,
                'destinations'           => $destinations,

                'notificationId'         => null,
                'txid'                   => $parsed_tx['txid'],
                'transactionTime'        => DateTimeUtil::ISO8601Date($parsed_tx['timestamp']),
                'confirmed'              => ($confirmations > 0 ? true : false),
                'confirmations'          => $confirmations,
                'confirmationTime'       => $confirmation_timestamp ? DateTimeUtil::ISO8601Date($confirmation_timestamp) : '',
                'blockSeq'               => $block_seq,

                'notifiedAddress'        => null,
                'notifiedAddressId'      => null,
                'notifiedMonitorId'      => null,
                'requestId'              => null,

                'bitcoinTx'              => $parsed_tx['bitcoinTx'],

                'transactionFingerprint' => isset($parsed_tx['transactionFingerprint']) ? $parsed_tx['transactionFingerprint'] : null,
            ];

            if ($monitored_address) {
                $notification['notifiedAddress']   = $monitored_address['address'];
                $notification['notifiedAddressId'] = $monitored_address['uuid'];
                unset($notification['notifiedMonitorId']);
            } else if ($event_monitor) {
                unset($notification['notifiedAddress']);
                unset($notification['notifiedAddressId']);
                $notification['notifiedMonitorId'] = $event_monitor['uuid'];
            }

            // for sends, try and find the send request by the txid
            if ($event_type == 'send') {
                $loaded_send_model = $this->loadSendModelByTxidAndAddress($parsed_tx['txid'], $monitored_address['address']);
                if ($loaded_send_model) {
                    $notification['requestId'] = $loaded_send_model['request_id'];
                } else {
                    EventLog::warning('send.noSendModel', ['txid' => $parsed_tx['txid']]);
                }
            } else {
                unset($notification['requestId']);
            }
        }
        if ($block_seq === null) { unset($notification['blockSeq']); }


        return $notification;
    }
    
    protected function loadSendModelByTxidAndAddress($txid, $bitcoin_address) {
        $loaded_send_model = $this->send_repository->findByTXID($txid);

        if (!$loaded_send_model) {
            // no txid was assigned yet to this send
            //   try to find recent sends with tx proposals and assign the txid if we can
            $any_resolved = $this->resolveTXIDsFromAddress($bitcoin_address);
            if ($any_resolved) {
                $loaded_send_model = $this->send_repository->findByTXID($txid);
            }
        }

        return $loaded_send_model;
    }

    protected function wlog($text) {
        Log::info($text);
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

    // ------------------------------------------------------------------------
    
    protected function sendNotificationsForInvalidatedProvisionalTransaction($invalidated_parsed_tx, $replacing_parsed_tx, $found_addresses, $confirmations, $block_seq, $block) {
        // build sources and destinations
        $sources = ($invalidated_parsed_tx['sources'] ? $invalidated_parsed_tx['sources'] : []);
        $destinations = ($invalidated_parsed_tx['destinations'] ? $invalidated_parsed_tx['destinations'] : []);

        $matched_monitored_address_ids = [];

        // loop through all matched monitored addresses
        foreach($found_addresses['matched_monitored_addresses'] as $monitored_address) {
            // build the notification
            $notification = $this->buildInvalidatedNotification($invalidated_parsed_tx, $replacing_parsed_tx, $sources, $destinations, $confirmations, $block_seq, $block, $monitored_address);
            $this->wlog("\$invalidated_parsed_tx['timestamp']={$invalidated_parsed_tx['timestamp']}");


            // create a notification
            $notification_vars_for_model = $notification;
            unset($notification_vars_for_model['notificationId']);

            try {
                // Log::debug("creating notification: ".json_encode(['txid' => $invalidated_parsed_tx['txid'], 'confirmations' => $confirmations, 'block_id' => $block ? $block['id'] : null,], 192));
                // Log::debug("sendNotificationsForInvalidatedProvisionalTransaction inserting new notification: ".json_encode(['txid' => $invalidated_parsed_tx['txid'], 'monitored_address_id' => $monitored_address['id'], 'confirmations' => $confirmations, 'event_type' => 'invalidation',], 192));
                $notification_model = $this->notification_repository->createForMonitoredAddress(
                    $monitored_address,
                    [
                        'txid'          => $invalidated_parsed_tx['txid'],
                        'confirmations' => $confirmations,
                        'notification'  => $notification_vars_for_model,
                        'block_id'      => $block ? $block['id'] : null,
                        'event_type'    => 'invalidation',
                    ]
                );
            } catch (QueryException $e) {
                if ($e->errorInfo[0] == 23000) {
                    EventLog::logError('notification.duplicate.error', $e, ['txid' => $invalidated_parsed_tx['txid'], 'monitored_address_id' => $monitored_address['id'], 'confirmations' => $confirmations, 'event_type' => 'invalidation',]);
                    continue;
                } else {
                    throw $e;
                }
            }

            // apply user API token and key
            $user = $this->userByID($monitored_address['user_id']);

            // update notification
            $notification['notificationId'] = $notification_model['uuid'];

            // put notification in the queue
            EventLog::log('notification.out', ['event'=>$notification['event'], 'invalidTxid'=>$notification['invalidTxid'], 'replacingTxid'=>$notification['replacingTxid'], 'endpoint'=>$user['webhook_endpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);

            $this->xcaller_client->sendWebhook($notification, $monitored_address['webhookEndpoint'], $notification_model['uuid'], $user['apitoken'], $user['apisecretkey']);
        }
    }

    protected function buildInvalidatedNotification($invalidated_parsed_tx, $replacing_parsed_tx, $sources, $destinations, $confirmations, $block_seq, $block, $monitored_address) {
        $quantity = $this->buildQuantityForEventType($monitored_address['monitor_type'], $invalidated_parsed_tx, $monitored_address['address']);
        $invalid_notificaton = $this->buildNotification($monitored_address['monitor_type'], $invalidated_parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address);

        // build a generic notification for the new transaction
        $replacing_notification = $this->buildNotification(null, $replacing_parsed_tx, null, $sources, $destinations, $confirmations, $block, $block_seq, null);

        $notification = [
            'event'                 => 'invalidation',

            'notificationId'        => null,

            'notifiedAddress'       => $monitored_address['address'],
            'notifiedAddressId'     => $monitored_address['uuid'],

            'invalidTxid'           => $invalidated_parsed_tx['txid'],
            'replacingTxid'         => $replacing_parsed_tx['txid'],

            'invalidNotification'   => $invalid_notificaton,
            'replacingNotification' => $replacing_notification,
        ];

        return $notification;
    }

    // ------------------------------------------------------------------------
    
    protected function resolveTXIDsFromAddress($bitcoin_address) {
        $any_resolved = false;

        // find all payment addresses for this bitcoin address
        $payment_addresses = $this->payment_address_repository->findByAddress($bitcoin_address)->get();

        foreach($payment_addresses as $payment_address) {
            try {
                
                // check for any sends for this payment address that don't have a txid yet
                $sends = $this->send_repository->findByPaymentAddressWithPendingMultisigTransactionId($payment_address);
                if (!$sends) { continue; }

                // get the wallet and the associated client
                $wallet = $payment_address->getCopayWallet();
                $copay_client = $payment_address->getCopayClient($wallet);

                foreach($sends as $send) {
                    // get the transaction proposal from copay
                    EventLog::debug('copay.transactionLookupBegin', ['address' => $bitcoin_address, 'tx_proposal_id' => $send['tx_proposal_id']]);
                    $transaction_proposal = $copay_client->getTransactionProposal($wallet, $send['tx_proposal_id']);

                    if ($transaction_proposal) {

                        // check for a txid - rejected sends won't have a txid yet
                        if (!isset($transaction_proposal['txid']) OR !$transaction_proposal['txid']) {
                            if ($transaction_proposal['status'] == 'rejected') {
                                // perhaps delete rejected txids here...
                            }
                            continue;
                        }

                        // make sure the status is 'broadcasted'
                        if ($transaction_proposal['status'] != 'broadcasted') {
                            EventLog::warning('copay.unexpectedStatus', ['status' => $transaction_proposal['status'], 'txid' => $transaction_proposal['txid']]);
                            continue;
                        }

                        // update the transaction ID
                    EventLog::debug('copay.transactionLookupFinish', ['address' => $bitcoin_address, 'txid' => $transaction_proposal['txid'], 'tx_proposal_id' => $send['tx_proposal_id']]);
                        $this->send_repository->update($send, ['txid' => $transaction_proposal['txid']]);
                        $any_resolved = true;
                    }
                }
            } catch (Exception $e) {
                EventLog::logError('copay.resolveTxidError', $e, ['address' => $bitcoin_address]);
            }
        }

        return $any_resolved;
    }

}
