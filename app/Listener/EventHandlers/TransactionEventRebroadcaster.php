<?php

namespace App\Listener\EventHandlers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use \Exception;

/*
* TransactionEventRebroadcaster
*
* Rebroadcasts transactions by event type for other listeners to subscribe to specific event types
*/
class TransactionEventRebroadcaster
{


    public function rebroadcast($parsed_tx, $confirmations, $block_seq, $block)
    {

        if ($parsed_tx['network'] == 'counterparty') {
            $type = isset($parsed_tx['counterpartyTx']['type']) ? $parsed_tx['counterpartyTx']['type'] : null;
            if ($type) {
                Event::fire('xchain.counterpartyTx.'.$type.'', [$parsed_tx['counterpartyTx'], $confirmations, $parsed_tx]);
            }
        }
    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received',  'App\Listener\EventHandlers\TransactionEventRebroadcaster@rebroadcast');
        $events->listen('xchain.tx.confirmed', 'App\Listener\EventHandlers\TransactionEventRebroadcaster@rebroadcast');
    }

}
