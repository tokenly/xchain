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


    protected function preprocessSendNotification($parsed_tx, $confirmations, $block_seq, $block_confirmation_time, $matched_monitored_address_ids) {
        // for counterparty, we need to validate all confirmed transactions with counterpartyd
        if ($confirmations == 0) {
            // unconfirmed transactions are forwarded ahead.  They will be validated when they confirm.
            return true;
        }

        // if the parsed transaction has not been verified with counterpartyd, then push it through it into the counterparty verification queue
        $is_validated = (isset($parsed_tx['counterpartyTx']['validated']) AND $parsed_tx['counterpartyTx']['validated']);
        if (!$is_validated) {
            // throw it into the counterpartyd verification queue
            $data = [
                'tx'                            => $parsed_tx,
                'confirmations'                 => $confirmations,
                'block_seq'                     => $block_seq,
                'block_confirmation_time'       => $block_confirmation_time,
            ];
            $this->queue_manager
                ->connection('blockingbeanstalkd')
                ->push('App\Jobs\XChain\ValidateConfirmedCounterpartydTxJob', $data, 'validate_counterpartytx');

            // return false so the notification isn't sent out without being validated
            return false;
        }

        return true;
    }

}
