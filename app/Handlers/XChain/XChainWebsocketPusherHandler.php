<?php 

namespace App\Handlers\XChain;

use Tokenly\PusherClient\Client as PusherClient;
use Illuminate\Contracts\Logging\Log;

class XChainWebsocketPusherHandler {

    public function __construct(PusherClient $pusher, Log $log) {
        $this->pusher = $pusher;
        $this->log = $log;
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

        // $this->log->info('sending notification', $notification);
        $this->pusher->send('/tx', $notification);

    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainWebsocketPusherHandler@pushEvent');
    }

}
