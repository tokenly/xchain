<?php

namespace App\Handlers\XChain\Network\Counterparty;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionHandler;
use App\Models\EventMonitor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class CounterpartyTransactionHandler extends BitcoinTransactionHandler {


    public function willNeedToPreprocessNotification($parsed_tx, $confirmations) {
        // for counterparty, we need to validate all confirmed transactions with counterpartyd
        if ($confirmations == 0) {
            // unconfirmed transactions are forwarded ahead.  They will be validated when they confirm.
            return false;
        }

        // if the parsed transaction has not been verified with counterpartyd, then push it through it into the counterparty verification queue
        $is_validated = (isset($parsed_tx['counterpartyTx']['validated']) AND $parsed_tx['counterpartyTx']['validated']);

        if ($is_validated) {
            // if this transactions was already validated
            return false;
        }


        // all other confirmed counterparty transactions must be validated
        return true;
    }

    public function preprocessNotification($parsed_tx, $confirmations, $block_seq, $block) {
        // throw this transaction into the counterpartyd verification queue
        $data = [
            'tx'            => $parsed_tx,
            'confirmations' => $confirmations,
            'block_seq'     => $block_seq,
            'block_id'      => $block['id'],
        ];
        // Log::debug("pushing ValidateConfirmedCounterpartydTxJob ".json_encode($data['block_id'], 192));
        Queue::connection('blockingbeanstalkd')
            ->push('App\Jobs\XChain\ValidateConfirmedCounterpartydTxJob', $data, 'validate_counterpartytx');
    }


    // ------------------------------------------------------------------------

    protected function buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address, $event_monitor=null) {
        $counterparty_event_type = $event_type;
        if ($event_type AND $parsed_tx['network'] == 'counterparty' AND $parsed_tx['counterpartyTx']['type'] == 'issuance') {
            $counterparty_event_type = 'issuance';
        }
        if ($event_type == 'send' AND $counterparty_event_type == 'issuance') {
            // don't notify the send address for issuances
            return null;
        }

        $notification = parent::buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address, $event_monitor);

        // add the counterparty Tx details
        $notification['counterpartyTx'] = $parsed_tx['counterpartyTx'];

        // update the event type
        $notification['event'] = $counterparty_event_type;

        return $notification;
    }



    protected function getEventMonitorType($parsed_tx) {
        if (isset($parsed_tx['counterpartyTx']['type'])) {
            $raw_event_type = $parsed_tx['counterpartyTx']['type'];

            // only block, issuance, broadcast are valid
            if (EventMonitor::isValidTypeString($raw_event_type)) {
                return $raw_event_type;
            }

            return null;
        }

        return null;
    }

}
