<?php 

namespace App\Handlers\XChain;

use App\Blockchain\Transaction\TransactionStore;
use App\Repositories\BlockRepository;
use App\Repositories\TransactionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Logging\Log;
use Exception;

class XChainBlockHandler {

    const MAX_CONFIRMATIONS = 6;

    public function __construct(TransactionStore $transaction_store, TransactionRepository $transaction_repository, BlockRepository $block_repository, Dispatcher $events, Log $log) {
        $this->transaction_store      = $transaction_store;
        $this->transaction_repository = $transaction_repository;
        $this->block_repository       = $block_repository;
        $this->events                 = $events;
        $this->log                    = $log;
    }

    public function handleNewBlock($block_event)
    {
        $this->wlog('$block_event: '."\n".json_encode($block_event, 192));

        // handle orphan blocks
        

        // update the block repository
        $new_block_model = $this->block_repository->create([
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
                    $parsed_tx = $cached_transaction['parsed_tx'];
                    $parsed_tx['bitcoinTx']['blockhash'] = $block_event['hash'];
                    $parsed_tx['bitcoinTx']['blocktime'] = $block_event['time'];


                    $this->wlog("transaction $txid was confirmed in block: {$transaction['block_confirmed_hash']}");

                    // update the transaction
                    if ($transaction['block_confirmed_hash']) {
                        // echo "\$transaction['block_confirmed_hash']:\n".json_encode($transaction['block_confirmed_hash'], 192)."\n";
                        // this is a previously confirmed transaction
                        $block_hash_for_transaction = $transaction['block_confirmed_hash'];
                        $confirmations = $this->getConfirmationsForBlockHash($block_hash_for_transaction, $block_event['height']);
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
        $blocks = $this->block_repository->findAllAsOfHeight($block_event['height'] - (self::MAX_CONFIRMATIONS - 1));
        $block_hashes = [];
        foreach($blocks as $block) { $block_hashes[] = $block['hash']; }
        foreach($this->transaction_repository->findAllTransactionsConfirmedInBlockHashes($block_hashes) as $transaction_model) {
            $confirmations = $this->getConfirmationsForBlockHash($transaction_model['block_confirmed_hash'], $block_event['height']);
            $this->events->fire('xchain.tx.confirmed', [$transaction_model['parsed_tx'], $confirmations]);
        }

    }

    public function subscribe($events) {
        $events->listen('xchain.block.received', 'App\Handlers\XChain\XChainBlockHandler@handleNewBlock');
    }

    protected function getConfirmationsForBlockHash($hash, $current_height) {
        $block = $this->block_repository->findByHash($hash);
        if (!$block) { return null; }
        return $current_height - $block['height'] + 1;

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
