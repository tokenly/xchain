<?php 

namespace App\Handlers\XChain;

use Nc\FayeClient\Client as FayeClient;
use Illuminate\Contracts\Logging\Log;

class XChainWebsocketPusherHandler {

    public function __construct(FayeClient $faye, Log $log) {
        $this->faye = $faye;
        $this->log = $log;
    }

    public function pushEvent($tx_event)
    {
        $xcp_data = $tx_event['counterpartyTx'];

        $notification = [
            'txid'             => $tx_event['txid'],
            'isCounterpartyTx' => $tx_event['isCounterpartyTx'],
            'type'             => $tx_event['isCounterpartyTx'] ? $tx_event['counterPartyTxType'] : 'bitcoin',

            'quantity'         => $tx_event['quantity'],
            'asset'            => $tx_event['asset'],
            'source'           => ($tx_event['sources'] ? $tx_event['sources'][0] : null),
            'destination'      => ($tx_event['destinations'] ? $tx_event['destinations'][0] : null),
        ];

        // $this->log->info('sending notification', $notification);
    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainWebsocketPusherHandler@pushEvent');
    }

}
