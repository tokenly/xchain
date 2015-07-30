<?php

namespace App\Handlers\XChain;

use App\Handlers\XChain\Network\Factory\NetworkHandlerFactory;
use App\Models\Block;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHP_Timer;

class XChainTransactionHandler {

    public function __construct(NetworkHandlerFactory $network_handler_factory) {
        $this->network_handler_factory = $network_handler_factory;
    }

    public function storeParsedTransaction($parsed_tx) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        // if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }
        $result = $transaction_handler->storeParsedTransaction($parsed_tx);
        // if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for storeParsedTransaction: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }
    }

    public function sendNotifications($parsed_tx, $confirmations, $block_seq, $block) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        // if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }
        $result = $transaction_handler->sendNotifications($parsed_tx, $confirmations, $block_seq, $block);
        // if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for sendNotifications: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }
    }

    public function updateAccountBalances($parsed_tx, $confirmations, $block_seq, $block) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);
        $result = $transaction_handler->updateAccountBalances($parsed_tx, $confirmations, $block_seq, $block);
        return $result;
    }

    public function handleConfirmedTransaction($parsed_tx, $confirmations, $block_seq, Block $block) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);
        $transaction_handler->sendNotifications($parsed_tx, $confirmations, $block_seq, $block);
        $transaction_handler->updateAccountBalances($parsed_tx, $confirmations, $block_seq, $block);
        return;
    }


    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@storeParsedTransaction');
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@sendNotifications');
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@updateAccountBalances');
        $events->listen('xchain.tx.confirmed', 'App\Handlers\XChain\XChainTransactionHandler@handleConfirmedTransaction');
    }


}
