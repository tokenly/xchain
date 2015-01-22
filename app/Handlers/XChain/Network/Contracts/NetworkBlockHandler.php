<?php

namespace App\Handlers\XChain\Network\Contracts;

/**
 * This is invoked when a new block is received
 */
interface NetworkBlockHandler {

    public function handleNewBlock($block_event);

    public function processBlock($block_event);

    public function updateAllBlockTransactions($block_event);

    public function generateAndSendNotifications($block_event, $block_confirmations);

}