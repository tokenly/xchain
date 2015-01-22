<?php

namespace App\Handlers\XChain;

use App\Handlers\XChain\Network\Factory\NetworkHandlerFactory;
use Exception;

/**
 * This is invoked when a new block is received
 */
class XChainBlockHandler {

    public function __construct(NetworkHandlerFactory $network_handler_factory) {
        $this->network_handler_factory = $network_handler_factory;
    }

    public function handleNewBlock($block_event) {
        $block_handler = $this->network_handler_factory->buildBlockHandler($block_event['network']);
        return $block_handler->handleNewBlock($block_event);
    }

    public function processBlock($block_event) {
        $block_handler = $this->network_handler_factory->buildBlockHandler($block_event['network']);
        return $block_handler->processBlock($block_event);
    }

    public function updateAllBlockTransactions($block_event) {
        $block_handler = $this->network_handler_factory->buildBlockHandler($block_event['network']);
        return $block_handler->updateAllBlockTransactions($block_event);
    }

    public function generateAndSendNotifications($block_event, $block_confirmations) {
        $block_handler = $this->network_handler_factory->buildBlockHandler($block_event['network']);
        return $block_handler->generateAndSendNotifications($block_event, $block_confirmations);
    }


    public function subscribe($events) {
        $events->listen('xchain.block.received', 'App\Handlers\XChain\XChainBlockHandler@handleNewBlock');
    }

}
