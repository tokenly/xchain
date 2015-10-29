<?php 

namespace App\Handlers\XChain\Network\Contracts;

use App\Models\Block;
use App\Models\Transaction;

/**
 * Invoked when a new transaction is received
 */
interface NetworkTransactionHandler {

    public function storeProvisionalTransaction(Transaction $transaction, $found_addresses);
    
    public function storeParsedTransaction($parsed_tx);

    public function sendNotifications($found_addresses, $parsed_tx, $confirmations, $block_seq, Block $block=null);

    public function updateAccountBalances($found_addresses, $parsed_tx, $confirmations, $block_seq, Block $block=null);

    public function updateUTXOs($found_addresses, $parsed_tx, $confirmations, $block_seq, Block $block=null);

}
