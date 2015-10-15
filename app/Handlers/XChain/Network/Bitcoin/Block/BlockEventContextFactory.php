<?php

namespace App\Handlers\XChain\Network\Bitcoin\Block;


use App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContext;
use App\Handlers\XChain\Network\Bitcoin\Block\ProvisionalTransactionInvalidationHandler;
use App\Repositories\ProvisionalTransactionRepository;
use Illuminate\Support\Facades\Log;
use \Exception;

/*
* BlockEventContextFactory
*/
class BlockEventContextFactory
{
    public function __construct(ProvisionalTransactionInvalidationHandler $provisional_transaction_invalidation_handler) {
        $this->provisional_transaction_invalidation_handler = $provisional_transaction_invalidation_handler;
    }

    public function newBlockEventContext() {
        $block_event_context = new BlockEventContext();

        // load all provisional transactions by UTXO
        $block_event_context['provisional_txids_by_utxo'] = $this->provisional_transaction_invalidation_handler->buildProvisionalTransactionsByUTXO();

        return $block_event_context;
    }

}