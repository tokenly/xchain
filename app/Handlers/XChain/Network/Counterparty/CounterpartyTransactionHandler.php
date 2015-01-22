<?php

namespace App\Handlers\XChain\Network\Counterparty;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionHandler;

class CounterpartyTransactionHandler extends BitcoinTransactionHandler {


    protected function buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block_confirmation_time, $block_seq, $monitored_address) {
        $notification = parent::buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block_confirmation_time, $block_seq, $monitored_address);

        // add the counterparty Tx details
        $notification['counterpartyTx'] = $parsed_tx['counterpartyTx'];

        return $notification;
    }


}
