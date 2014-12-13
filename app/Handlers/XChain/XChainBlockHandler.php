<?php 

namespace App\Handlers\XChain;

use App\Blockchain\Block\BlockChainStore;
use App\Blockchain\Block\ConfirmationsBuilder;
use App\Blockchain\Transaction\TransactionStore;
use App\Providers\EventLog\Facade\EventLog;
use App\Repositories\TransactionRepository;
use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Logging\Log;

/**
 * This is invoked when a new block is received
 */
class XChainBlockHandler {

    const MAX_CONFIRMATIONS_TO_NOTIFY = 6;

    public function __construct(TransactionStore $transaction_store, TransactionRepository $transaction_repository, BlockChainStore $blockchain_store, ConfirmationsBuilder $confirmations_builder, Dispatcher $events, Log $log) {
        $this->transaction_store      = $transaction_store;
        $this->transaction_repository = $transaction_repository;
        $this->confirmations_builder  = $confirmations_builder;
        $this->blockchain_store       = $blockchain_store;
        $this->events                 = $events;
        $this->log                    = $log;
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
        $new_block_model = $this->blockchain_store->create([
            'hash'         => $block_event['hash'],
            'height'       => $block_event['height'],
            'parsed_block' => $block_event
        ]);


        // update all transactions that were in this block
        foreach ($block_event['tx'] as $txid) {
            $this->wlog("BlockHandler tx: $txid");

            // get the cached transaction
            $cached_transaction = $this->transaction_store->getCachedTransaction($txid);

            if ($cached_transaction) {
                // check to see if block hash is correct
                $transaction = $cached_transaction;
                if (!$transaction['block_confirmed_hash'] OR $transaction['block_confirmed_hash'] != $block_event['hash']) {
                    // update the parsed_tx
                    $parsed_tx = $transaction['parsed_tx'];
                    $parsed_tx['bitcoinTx']['blockhash'] = $block_event['hash'];
                    $parsed_tx['bitcoinTx']['blocktime'] = $block_event['time'];

                    // check to see if it was reorganized
                    $was_reorganized = ($transaction['block_confirmed_hash'] AND $transaction['block_confirmed_hash'] != $block_event['hash']);
                    if ($was_reorganized) {
                        // we must reload this transaction from insight
                        // since this transaction was reorganized
                        $transaction = $this->transaction_store->getParsedTransactionFromInsight($txid);
                    }

                    $this->wlog("transaction $txid was confirmed in block: {$transaction['block_confirmed_hash']}");

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
                    ]);
                }
            } else {
                $this->wlog("transaction $txid was not found.  Loading from insight.");

                // no cached transaction - load from Insight
                $transaction = $this->transaction_store->getParsedTransactionFromInsight($txid);
                $confirmations = $transaction['parsed_tx']['bitcoinTx']['confirmations'];
            }
        }

        // send notifications
        // also update every transaction that needs a new confirmation sent
        //   find all transactions in the last 6 blocks
        //   and send out notifications
        // echo "\$this->blockchain_store->findAll()->all():\n".json_encode($this->blockchain_store->findAll()->all(), 192)."\n";
        $blocks = $this->blockchain_store->findAllAsOfHeightEndingWithBlockhash($block_event['height'] - (self::MAX_CONFIRMATIONS_TO_NOTIFY - 1), $block_event['hash']);
        // echo "\$blocks:\n".json_encode($blocks, 192)."\n";
        $block_hashes = [];
        foreach($blocks as $block) { $block_hashes[] = $block['hash']; }
        foreach($this->transaction_repository->findAllTransactionsConfirmedInBlockHashes($block_hashes) as $transaction_model) {
            $confirmations = $this->confirmations_builder->getConfirmationsForBlockHashAsOfHeight($transaction_model['block_confirmed_hash'], $block_event['height']);
            $this->events->fire('xchain.tx.confirmed', [$transaction_model['parsed_tx'], $confirmations]);
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
