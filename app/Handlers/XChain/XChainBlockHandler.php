<?php 

namespace App\Handlers\XChain;

use Illuminate\Contracts\Logging\Log;

class XChainBlockHandler {

    public function __construct(Log $log) {
        $this->log                          = $log;
    }

    public function handleNewBlock($block_event)
    {
        $this->wlog('$block_event: '."\n".json_encode($block_event, 192));
    }

    public function subscribe($events) {
        $events->listen('xchain.block.received', 'App\Handlers\XChain\XChainBlockHandler@handleNewBlock');
    }

    protected function wlog($text) {
        $this->log->info($text);
    }

}

/*

[2014-12-05 12:54:00] local.INFO: $block_event:
{
    "hash": "00000000000000001496168e81641f5aa47347bc9d50b996009f987bc0309542",
    "height": 332985,
    "previousblockhash": "00000000000000000d5097dc4283d83ad513fdc6bcaa2359ae1e374534fda2fb",
    "time": 1417784016,
    "tx": [
        "74775794ff9b50cc502b2f3a893c20ecef92ac5adfbb38ee1f025f3c5c88908b",
        "0621b37c83c7570eeb5c7cd05cd388daa10bdbff91a3f533b9a6a206e862d1e9",
        "5c1e2530a41f1cb1993f9f13530b23cd278bff1cb4cda73dfd5e243276338faa",

*/
