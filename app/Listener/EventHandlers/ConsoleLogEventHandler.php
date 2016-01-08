<?php

namespace App\Listener\EventHandlers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;
use PHP_Timer;

/*
* ConsoleLogEventHandler
*/
class ConsoleLogEventHandler
{
    public function __construct()
    {
    }

    public function logToConsole($tx_event)
    {
        if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }

        if (!isset($GLOBALS['XCHAIN_GLOBAL_COUNTER'])) { $GLOBALS['XCHAIN_GLOBAL_COUNTER'] = 0; }
        $count = ++$GLOBALS['XCHAIN_GLOBAL_COUNTER'];

        $xcp_data = $tx_event['counterpartyTx'];
        if ($tx_event['network'] == 'counterparty') {
            if ($xcp_data['type'] == 'send') {
                // $this->wlog("from: {$xcp_data['sources'][0]} to {$xcp_data['destinations'][0]}: {$xcp_data['quantity']} {$xcp_data['asset']} [{$tx_event['txid']}]");
                EventLog::info('xcp.send', [
                    'type'  => $xcp_data['type'],
                    'from'  => $xcp_data['sources'],
                    'to'    => $xcp_data['destinations'],
                    'qty'   => $xcp_data['quantity'],
                    'asset' => $xcp_data['asset'],
                    'txid'  => $tx_event['txid'],
                ]);
            } else {
                EventLog::info('xcp.other', [
                    'type'  => $xcp_data['type'],
                    'txid'  => $tx_event['txid'],
                ]);
            }
        } else {
            if (rand(1, 100) === 1) {
                $c = Carbon::createFromTimestampUTC(floor($tx_event['timestamp']))->timezone(new \DateTimeZone('America/Chicago'));
                $this->wlog("heard $count tx.  Last tx time: ".$c->format('Y-m-d h:i:s A T'));
            }
            EventLog::jsonLog('info', 'tx.heard');
        }

        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for logToConsole: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }
    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Listener\EventHandlers\ConsoleLogEventHandler@logToConsole');
    }

    protected function wlog($text) {
        Log::info($text);
    }
}
