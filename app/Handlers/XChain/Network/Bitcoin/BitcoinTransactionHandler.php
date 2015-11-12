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
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\ProvisionalTransactionRepository;
use App\Repositories\UserRepository;
use App\Util\DateTimeUtil;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XcallerClient\Client;

class BitcoinTransactionHandler implements NetworkTransactionHandler {

    const CONFIRMATIONS_TO_INVALIDATE_PROVISIONAL_TRANSACTIONS = 2;

    public function __construct(MonitoredAddressRepository $monitored_address_repository, PaymentAddressRepository $payment_address_repository, UserRepository $user_repository, NotificationRepository $notification_repository, BitcoinTransactionStore $transaction_store, ProvisionalTransactionRepository $provisional_transaction_repository, Client $xcaller_client, BlockEventContextFactory $block_event_context_factory, ProvisionalTransactionInvalidationHandler $provisional_transaction_invalidation_handler) {
        $this->monitored_address_repository                 = $monitored_address_repository;
        $this->payment_address_repository                   = $payment_address_repository;
        $this->provisional_transaction_repository           = $provisional_transaction_repository;
        $this->user_repository                              = $user_repository;
        $this->notification_repository                      = $notification_repository;
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
        // don't process this now if pre-processing was necessary for the send notification
        if ($this->willNeedToPreprocessSendNotification($parsed_tx, $confirmations)) {
            return;
        }

        $sources      = ($parsed_tx['sources']      ? $parsed_tx['sources']      : []);
        $destinations = ($parsed_tx['destinations'] ? $parsed_tx['destinations'] : []);

        // determine matched payment addresses
        foreach($found_addresses['payment_addresses'] as $payment_address) {
            // Log::debug("upating account balances for payment address {$payment_address['address']}.  txid is {$parsed_tx['txid']}");
            
            $is_source      = in_array($payment_address['address'], $sources);
            $is_destination = in_array($payment_address['address'], $destinations);

            if ($is_source) {
                // this address sent something
                $quantity = $this->buildQuantityForEventType('send', $parsed_tx, $payment_address['address']);
                AccountHandler::send($payment_address, $quantity, $parsed_tx['asset'], $parsed_tx, $confirmations);
                continue;
            }

            if ($is_destination) {
                // this address received something
                $quantity = $this->buildQuantityForEventType('receive', $parsed_tx, $payment_address['address']);
                AccountHandler::receive($payment_address, $quantity, $parsed_tx['asset'], $parsed_tx, $confirmations);
                continue;
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
        // Counterparty transactions will need to validate any transfers with counterpartyd first
        if ($this->willNeedToPreprocessSendNotification($parsed_tx, $confirmations)) {
            $this->preprocessSendNotification($parsed_tx, $confirmations, $block_seq, $block);
            return;
        }


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

            $this->wlog("\$parsed_tx['timestamp']={$parsed_tx['timestamp']} transactionTime=".$notification['transactionTime']);


            // create a notification
            $notification_vars_for_model = $notification;
            unset($notification_vars_for_model['notificationId']);

            try {
                // Log::debug("creating notification: ".json_encode(['txid' => $parsed_tx['txid'], 'confirmations' => $confirmations, 'block_id' => $block ? $block['id'] : null,], 192));
                $notification_model = $this->notification_repository->createForMonitoredAddress(
                    $monitored_address,
                    [
                        'txid'          => $parsed_tx['txid'],
                        'confirmations' => $confirmations,
                        'notification'  => $notification_vars_for_model,
                        'block_id'      => $block ? $block['id'] : null,
                        'event_type'    => $event_type,
                    ]
                );
            } catch (QueryException $e) {
                if ($e->errorInfo[0] == 23000) {
                    EventLog::logError('notification.duplicate.error', $e, ['txid' => $parsed_tx['txid'], 'monitored_address_id' => $monitored_address['id'], 'confirmations' => $confirmations, 'event_type' => $event_type]);
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
            EventLog::log('notification.out', ['event'=>$notification['event'], 'txid'=>$notification['txid'], 'confirmations' => $confirmations, 'asset'=>$notification['asset'], 'quantity'=>$notification['quantity'], 'sources'=>$notification['sources'], 'destinations'=>$notification['destinations'], 'endpoint'=>$user['webhook_endpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);

            $this->xcaller_client->sendWebhook($notification, $monitored_address['webhookEndpoint'], $notification_model['uuid'], $user['apitoken'], $user['apisecretkey']);
        }

    }

    // if this returns true, then pre-processing is necessary and don't send the notificatoin
    protected function willNeedToPreprocessSendNotification($parsed_tx, $confirmations) {
        // bitcoin always sends unconfirmed and confirmed notifications immediately
        return false;
    }

    protected function preprocessSendNotification($parsed_tx, $confirmations, $block_seq, $block) {
        // for bitcoin, always send confirmed notifications
        //   because bitcoind has already validated the confirmed transaction

        // empty for bitcoin
    }

    protected function buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address) {
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

                'notifiedAddress'        => $monitored_address['address'],
                'notifiedAddressId'      => $monitored_address['uuid'],

                'bitcoinTx'              => $parsed_tx['bitcoinTx'],

                'transactionFingerprint' => isset($parsed_tx['transactionFingerprint']) ? $parsed_tx['transactionFingerprint'] : null,
            ];
        }
        if ($block_seq === null) { unset($notification['blockSeq']); }


        return $notification;
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

}
