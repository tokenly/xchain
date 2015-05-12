<?php

namespace App\Handlers\XChain;

use App\Handlers\XChain\Network\Factory\NetworkHandlerFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHP_Timer;

class XChainTransactionHandler {

    public function __construct(NetworkHandlerFactory $network_handler_factory) {
        $this->network_handler_factory = $network_handler_factory;
    }

    public function storeParsedTransaction($parsed_tx) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }
        $result = $transaction_handler->storeParsedTransaction($parsed_tx);
        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for storeParsedTransaction: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

        return $result;
    }

    public function sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }
        $result = $transaction_handler->sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time);
        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for sendNotifications: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

        return $result;
    }

    public function handleConfirmedTransaction($parsed_tx, $confirmations, $block_seq, $block_confirmation_time) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);
        return $transaction_handler->sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time);
    }


    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@storeParsedTransaction');
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@sendNotifications');
        $events->listen('xchain.tx.confirmed', 'App\Handlers\XChain\XChainTransactionHandler@handleConfirmedTransaction');
    }


}
