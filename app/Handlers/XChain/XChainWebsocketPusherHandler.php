<?php 

namespace App\Handlers\XChain;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHP_Timer;
use Tokenly\PusherClient\Client as PusherClient;

class XChainWebsocketPusherHandler {

    public function __construct(PusherClient $pusher) {
        $this->pusher = $pusher;
    }

    public function pushEvent($tx_event)
    {
        $xcp_data = $tx_event['counterpartyTx'];

        $source = ($tx_event['sources'] ? $tx_event['sources'][0] : null);
        $destination = ($tx_event['destinations'] ? $tx_event['destinations'][0] : null);
        $quantity = isset($tx_event['values'][$destination]) ? $tx_event['values'][$destination] : null;

        $notification = [
            'txid'        => $tx_event['txid'],
            'network'     => $tx_event['network'],
            'type'        => ($tx_event['network'] == 'counterparty') ? $tx_event['counterPartyTxType'] : 'bitcoin',

            'quantity'    => $quantity,
            'asset'       => $tx_event['asset'],
            'source'      => $source,
            'destination' => $destination,
        ];

        if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }
        $this->pusher->send('/tx', $notification);
        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for pushEvent: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainWebsocketPusherHandler@pushEvent');
    }

}
