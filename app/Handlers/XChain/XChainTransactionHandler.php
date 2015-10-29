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

    public function handleUnconfirmedTransaction($parsed_tx, $confirmations, $block_seq, $block) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        $transaction = $transaction_handler->storeParsedTransaction($parsed_tx);

        $found_addresses = $transaction_handler->findMonitoredAndPaymentAddressesByParsedTransaction($parsed_tx);
        $transaction_handler->storeProvisionalTransaction($transaction, $found_addresses);
        $transaction_handler->updateUTXOs($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
        $transaction_handler->updateAccountBalances($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
        $transaction_handler->sendNotifications($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);

        return;
    }



    public function handleConfirmedTransaction($parsed_tx, $confirmations, $block_seq, Block $block, $block_event_context=null) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        if ($block_event_context === null) { $block_event_context = $transaction_handler->newBlockEventContext(); }

        $found_addresses = $transaction_handler->findMonitoredAndPaymentAddressesByParsedTransaction($parsed_tx);

        $transaction_handler->updateProvisionalTransaction($parsed_tx, $found_addresses, $confirmations);
        $transaction_handler->invalidateProvisionalTransactions($found_addresses, $parsed_tx, $confirmations, $block_seq, $block, $block_event_context);
        $transaction_handler->updateUTXOs($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
        $transaction_handler->updateAccountBalances($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
        $transaction_handler->sendNotifications($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);

        return;
    }

    // ------------------------------------------------------------------------------------

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainTransactionHandler@handleUnconfirmedTransaction');
        $events->listen('xchain.tx.confirmed', 'App\Handlers\XChain\XChainTransactionHandler@handleConfirmedTransaction');
    }




}
