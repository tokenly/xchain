<?php

namespace App\Listener\EventHandlers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHP_Timer;
use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;

/*
* ConsoleLogEventHandler
*/
class ConsoleLogEventHandler
{

    const TX_HEARD_GROUP_BY = 10;

    public function __construct()
    {
    }

    public function logToConsole($tx_event)
    {
        if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { PHP_Timer::start(); }

        if (!isset($GLOBALS['XCHAIN_GLOBAL_COUNTER'])) { $GLOBALS['XCHAIN_GLOBAL_COUNTER'] = 0; }
        if (!isset($GLOBALS['XCHAIN_GLOBAL_COUNTER_STACK'])) { $GLOBALS['XCHAIN_GLOBAL_COUNTER_STACK'] = 0; }
        $in_memory_count = ++$GLOBALS['XCHAIN_GLOBAL_COUNTER'];
        $in_memory_stack = ++$GLOBALS['XCHAIN_GLOBAL_COUNTER_STACK'];

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
        }


        if (($in_memory_count % 100) === 1) {
            $c = Carbon::createFromTimestampUTC(floor($tx_event['timestamp']))->timezone(new \DateTimeZone('America/Chicago'));
            $this->wlog("heard $in_memory_count tx.  Last tx time: ".$c->format('Y-m-d h:i:s A T'));
        }

        if ($in_memory_stack >= self::TX_HEARD_GROUP_BY) {
            EventLog::jsonLog('info', 'tx.heard', ['txCount' => $in_memory_stack]);
            $GLOBALS['XCHAIN_GLOBAL_COUNTER_STACK'] -= $in_memory_stack;
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
