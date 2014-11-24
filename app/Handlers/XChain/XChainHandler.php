<?php 

namespace App\Handlers\XChain;

use Nc\FayeClient\Client as FayeClient;
use Illuminate\Contracts\Logging\Log;

class XChainHandler {

    public function __construct(FayeClient $faye, Log $log) {
        $this->faye = $faye;
        $this->log = $log;
    }

    public function pushEvent($tx_event)
    {
        $xcp_data = $tx_event['counterpartyTx'];

        $push_data = [
            'txid'             => $tx_event['txid'],
            'isCounterpartyTx' => $tx_event['isCounterpartyTx'],
            'type'             => $tx_event['isCounterpartyTx'] ? $tx_event['counterPartyTxType'] : 'bitcoin',
            'quantity'         => $tx_event['quantity'],
            'asset'            => $tx_event['asset'],
            'source'           => ($tx_event['sources'] ? $tx_event['sources'][0] : null),
            'destination'      => ($tx_event['destinations'] ? $tx_event['destinations'][0] : null),
            'asset'            => $tx_event['asset'],
        ];


        // push to faye
        // $this->wlog('sending to /tx: '.json_encode($push_data, 192));
        $this->faye->send('/tx', $push_data);

        // if ($tx_event['isCounterpartyTx']) {
        //     $this->wlog("[".date("Y-m-d H:i:s")."] XCP TX FOUND: {$xcp_data['type']} at {$tx_event['txid']}");
        //     if ($xcp_data['type'] == 'send') {
        //         $this->wlog("from: {$xcp_data['sources'][0]} to {$xcp_data['destinations'][0]}: {$xcp_data['quantity']} {$xcp_data['asset']}");
        //     }
        // } else {
        //     if (rand(1, 100) === 1) {
        //         $this->wlog("heard $count tx (".date("Y-m-d H:i:s").")");
        //     }
        // }

    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainHandler@pushEvent');
    }

    protected function wlog($text) {
        $this->log->info($text);
    }

}
