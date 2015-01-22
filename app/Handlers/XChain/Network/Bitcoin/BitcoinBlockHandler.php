<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use App\Blockchain\Block\BlockChainStore;
use App\Blockchain\Block\ConfirmationsBuilder;
use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore;
use App\Handlers\XChain\Network\Contracts\NetworkBlockHandler;
use App\Providers\EventLog\Facade\EventLog;
use App\Repositories\NotificationRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Util\DateTimeUtil;
use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Queue\QueueManager;

/**
 * This is invoked when a new block is received
 */
class BitcoinBlockHandler implements NetworkBlockHandler {

    const MAX_CONFIRMATIONS_TO_NOTIFY = 6;

    public function __construct(BitcoinTransactionStore $transaction_store, TransactionRepository $transaction_repository, NotificationRepository $notification_repository, UserRepository $user_repository, BlockChainStore $blockchain_store, ConfirmationsBuilder $confirmations_builder, QueueManager $queue_manager, Dispatcher $events, Log $log) {
        $this->transaction_store       = $transaction_store;
        $this->transaction_repository  = $transaction_repository;
        $this->notification_repository = $notification_repository;
        $this->user_repository         = $user_repository;
        $this->confirmations_builder   = $confirmations_builder;
        $this->blockchain_store        = $blockchain_store;
        $this->queue_manager           = $queue_manager;
        $this->events                  = $events;
        $this->log                     = $log;
    }

    public function handleNewBlock($block_event) {
        // backfill any missing blocks
        $missing_block_events = $this->blockchain_store->loadMissingBlockEventsFromInsight($block_event['previousblockhash']);

        // process missing blocks
        foreach($missing_block_events as $missing_block_event) {
            EventLog::log('block.missing', $missing_block_event, ['height', 'hash']);
            $this->processBlock($missing_block_event);
        }

        // lastly - process this block
        $this->processBlock($block_event);
    }

    public function processBlock($block_event)
    {
        EventLog::log('block', $block_event, ['height', 'hash', 'previousblockhash', 'time']);

        // update the block repository
        try {
            $new_block_model = $this->blockchain_store->create([
                'hash'         => $block_event['hash'],
                'height'       => $block_event['height'],
                'parsed_block' => $block_event
            ]);
        } catch (Exception $e) {
            if (strpos($e->getCode(), '23') === 0) {
                EventLog::logError('block.duplicate.error', $e, ['hash' => $block_event['hash'], 'height' => $block_event['height'],]);
                sleep(5);
                return;
            } 

            EventLog::logError('block.error', $e);
            sleep(5);
            throw $e;
        }

        // update the block transactions in this block
        $block_confirmations = $this->updateAllBlockTransactions($block_event);
        $this->generateAndSendNotifications($block_event, $block_confirmations);
    }

    public function updateAllBlockTransactions($block_event) {
        // update all transactions that were in this block
        $block_seq = 0;
        $tx_count = count($block_event['tx']);
        foreach ($block_event['tx'] as $txid) {
            if ($block_seq % 100 == 1 AND $block_seq < ($tx_count - 1)) {
                $this->wlog("BlockHandler processed ".($block_seq)." of {$tx_count}");
            }

            // get the cached transaction
            $cached_transaction = $this->transaction_store->getCachedTransaction($txid);

            if ($cached_transaction) {
                // check to see if block hash is correct
                $transaction = $cached_transaction;
                if (!$transaction['block_confirmed_hash'] OR $transaction['block_confirmed_hash'] != $block_event['hash'] OR $transaction['block_seq'] != $block_seq) {
                    // update the parsed_tx
                    $parsed_tx = $transaction['parsed_tx'];
                    $parsed_tx['bitcoinTx']['blockhash'] = $block_event['hash'];
                    $parsed_tx['bitcoinTx']['blocktime'] = $block_event['time'];

                    // also inject the block height
                    $parsed_tx['bitcoinTx']['blockheight'] = $block_event['height'];

                    // check to see if it was reorganized
                    $was_reorganized = ($transaction['block_confirmed_hash'] AND $transaction['block_confirmed_hash'] != $block_event['hash']);
                    if ($was_reorganized) {
                        // we must reload this transaction from insight
                        // since this transaction was reorganized
                        $transaction = $this->transaction_store->getParsedTransactionFromInsight($txid, $block_seq);
                    }

                    // update the transaction
                    if ($transaction['block_confirmed_hash']) {
                        // echo "\$transaction['block_confirmed_hash']:\n".json_encode($transaction['block_confirmed_hash'], 192)."\n";
                        // this is a previously confirmed transaction
                        $block_hash_for_transaction = $transaction['block_confirmed_hash'];
                        $confirmations = $this->confirmations_builder->getConfirmationsForBlockHashAsOfHeight($block_hash_for_transaction, $block_event['height']);
                        $this->wlog("transaction was confirmed in block: {$transaction['block_confirmed_hash']} \$confirmations is $confirmations");
                        if ($confirmations === null) { throw new Exception("Unable to load confirmations for block {$block_hash_for_transaction}", 1); }
                    }
                    unset($parsed_tx['bitcoinTx']['confirmations']);

                    // update the transaction
                    $this->transaction_repository->update($transaction, [
                        'block_confirmed_hash' => $parsed_tx['bitcoinTx']['blockhash'],
                        'is_mempool'           => 0,
                        'parsed_tx'            => $parsed_tx,
                        'block_seq'            => $block_seq,
                    ]);
                }
            } else {
                $this->wlog("transaction $txid was not found.  Loading from insight.");

                // no cached transaction - load from Insight
                $transaction = $this->transaction_store->getParsedTransactionFromInsight($txid, $block_seq);
                $confirmations = $transaction['parsed_tx']['bitcoinTx']['confirmations'];
            }

            ++$block_seq;
        }
        $this->wlog("BlockHandler finished {$block_seq} of $tx_count");

        return $confirmations;
    }


    public function generateAndSendNotifications($block_event, $block_confirmations) {

        // send a new block notification
        $notification = [
            'event'             => 'block',
            'notificationId'    => null,

            'hash'              => $block_event['hash'],
            'height'            => $block_event['height'],
            'previousblockhash' => $block_event['previousblockhash'],
            'time'              => DateTimeUtil::ISO8601Date($block_event['time']),
        ];


        // send block notifications
        //   create a block notification for each user
        $notification_vars_for_model = $notification;
        unset($notification_vars_for_model['notificationId']);
        foreach ($this->user_repository->findWithWebhookEndpoint() as $user) {
            $notification_model = $this->notification_repository->createForUser(
                $user,
                [
                    'txid'          => $block_event['hash'],
                    'confirmations' => $block_confirmations,
                    'notification'  => $notification_vars_for_model,
                ]
            );

            $notification['notificationId'] = $notification_model['uuid'];
            $notification_json_string = json_encode($notification);

            $api_token = $user['apitoken'];
            $api_secret = $user['apisecretkey'];
            $signature = hash_hmac('sha256', $notification_json_string, $api_secret, false);


            $notification_entry = [
                'meta' => [
                    'id'        => $notification_model['uuid'],
                    'endpoint'  => $user['webhook_endpoint'],
                    'timestamp' => time(),
                    'apiToken'  => $api_token,
                    'signature' => $signature,
                    'attempt'   => 0,
                ],

                'payload' => $notification_json_string,
            ];

            // put notification in the queue
            $this->wlog('adding to notifications_out: '.substr(json_encode($notification_entry), 0, 500));
            EventLog::log('notification.out', ['event'=>$notification['event'], 'height'=>$notification['height'], 'hash'=>$notification['hash'], 'endpoint'=>$user['webhook_endpoint'], 'user'=>$user['id'], 'id' => $notification_model['uuid']]);
            $this->queue_manager
                ->connection('notifications_out')
                ->pushRaw(json_encode($notification_entry), 'notifications_out');
        }
        



        // send transaction notifications
        // also update every transaction that needs a new confirmation sent
        //   find all transactions in the last 6 blocks
        //   and send out notifications
        // echo "\$this->blockchain_store->findAll()->all():\n".json_encode($this->blockchain_store->findAll()->all(), 192)."\n";
        $blocks = $this->blockchain_store->findAllAsOfHeightEndingWithBlockhash($block_event['height'] - (self::MAX_CONFIRMATIONS_TO_NOTIFY - 1), $block_event['hash']);
        // echo "\$blocks:\n".json_encode($blocks, 192)."\n";
        $block_hashes = [];
        $blocks_by_hash = [];
        foreach($blocks as $block) {
            $block_hashes[] = $block['hash'];
            $blocks_by_hash[$block['hash']] = $block;
        }
        if ($block_hashes) {
            foreach($this->transaction_repository->findAllTransactionsConfirmedInBlockHashes($block_hashes) as $transaction_model) {
                $confirmations = $this->confirmations_builder->getConfirmationsForBlockHashAsOfHeight($transaction_model['block_confirmed_hash'], $block_event['height']);

                // the block height might have changed if the chain was reorganized
                $parsed_tx = $transaction_model['parsed_tx'];
                $block = $blocks_by_hash[$transaction_model['block_confirmed_hash']];
                $confirmation_timestamp = null;
                if ($block) {
                    $parsed_tx['bitcoinTx']['blockheight'] = $block['height'];
                    $confirmation_timestamp = $block['parsed_block']['time'];
                }

                $this->events->fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, $transaction_model['block_seq'], $confirmation_timestamp]);
            }
        } else {
            EventLog::logError('block.noBlocksFound', ['height' => $block_event['height'], 'hash' => $block_event['hash'], 'previousblockhash' => $block_event['previousblockhash']]);
        }

    }

    public function subscribe($events) {
        $events->listen('xchain.block.received', 'App\Handlers\XChain\XChainBlockHandler@handleNewBlock');
    }

    protected function wlog($text) {
        $this->log->info($text);
    }


}


/*

[2014-12-05 12:54:00] local.INFO: $block_event:
{
    "hash": "00000000000000001496168e81641f5aa47347bc9d50b996009f987bc0309542",
    "height": 332985,
    "previousblockhash": "00000000000000000d5097dc4283d83ad513fdc6bcaa2359ae1e374534fda2fb",
    "time": 1417784016,
    "tx": [
        "74775794ff9b50cc502b2f3a893c20ecef92ac5adfbb38ee1f025f3c5c88908b",
        "0621b37c83c7570eeb5c7cd05cd388daa10bdbff91a3f533b9a6a206e862d1e9",
        "5c1e2530a41f1cb1993f9f13530b23cd278bff1cb4cda73dfd5e243276338faa",

*/
