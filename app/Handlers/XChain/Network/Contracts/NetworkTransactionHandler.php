<?php 

namespace App\Handlers\XChain\Network\Contracts;

use App\Models\Block;

/**
 * Invoked when a new transaction is received
 */
interface NetworkTransactionHandler {

    public function storeParsedTransaction($parsed_tx);

    public function sendNotifications($parsed_tx, $confirmations, $block_seq, Block $block=null);

    public function updateAccountBalances($parsed_tx, $confirmations, $block_seq, Block $block=null);

}
