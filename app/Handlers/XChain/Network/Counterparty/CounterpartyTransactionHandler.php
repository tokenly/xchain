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

        // special case for issuance and broadcast
        if ($event_type AND $parsed_tx['network'] == 'counterparty') {
            switch ($parsed_tx['counterpartyTx']['type']) {
                case 'issuance':
                case 'broadcast':
                    $counterparty_event_type = $parsed_tx['counterpartyTx']['type'];
                    break;
            }
        }
        if ($event_type == 'send' AND $counterparty_event_type == 'issuance') {
            // don't notify the send address for issuances
            return null;
        }

        // for issuances, ensure that the tx is resolved
        $loaded_send_model = null;
        if ($counterparty_event_type == 'issuance') {
            $loaded_send_model = $this->loadSendModelByTxidAndAddress($parsed_tx['txid'], $monitored_address['address']);
        }

        $notification = parent::buildNotification($event_type, $parsed_tx, $quantity, $sources, $destinations, $confirmations, $block, $block_seq, $monitored_address, $event_monitor);

        // add the request id for issuances
        if ($counterparty_event_type == 'issuance' AND $loaded_send_model) {
            $notification['requestId'] = $loaded_send_model['request_id'];
        }

        // add the counterparty Tx details
        $notification['counterpartyTx'] = $parsed_tx['counterpartyTx'];

        // update the event type
        $notification['event'] = $counterparty_event_type;

        // for broadcasts, remove asset and quantity
        if ($counterparty_event_type == 'broadcast') {
            $notification['asset']        = null;
            $notification['quantity']     = 0;
            $notification['quantitySat']  = 0;
        }

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
