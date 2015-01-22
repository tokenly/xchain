<?php

namespace App\Handlers\XChain;

use App\Handlers\XChain\Network\Factory\NetworkHandlerFactory;


class XChainTransactionHandler {

    public function __construct(NetworkHandlerFactory $network_handler_factory) {
        $this->network_handler_factory = $network_handler_factory;
    }

    public function storeParsedTransaction($parsed_tx) {
        $block_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);
        return $block_handler->storeParsedTransaction($parsed_tx);
    }

    public function sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time) {
        $block_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);
        return $block_handler->sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time);
    }


    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@storeParsedTransaction');
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@sendNotifications');
        $events->listen('xchain.tx.confirmed', 'App\Handlers\XChain\XChainTransactionHandler@sendNotifications');
    }


}
