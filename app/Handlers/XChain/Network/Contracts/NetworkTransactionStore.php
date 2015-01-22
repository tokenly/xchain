<?php 

namespace App\Handlers\XChain\Network\Contracts;

/**
 * Invoked when a new transaction is received
 */
interface NetworkTransactionStore {

    public function getCachedTransaction($txid);

    public function getTransaction($txid);

    public function storeParsedTransaction($parsed_tx, $block_seq=null);

}
