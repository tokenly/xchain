<?php

namespace App\Listener\Job;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use \Exception;
use PHP_Timer;

/*
* BTCTransactionJob
*/
class BTCTransactionJob
{
    public function __construct(BitcoinTransactionEventBuilder $transaction_data_builder, Dispatcher $events)
    {
        $this->transaction_data_builder = $transaction_data_builder;
        $this->events                   = $events;
    }

    public function fire($job, $data)
    {
        $_debugLogTxTiming = Config::get('xchain.debugLogTxTiming');

        // build the event data
        if ($_debugLogTxTiming) { PHP_Timer::start(); }
        $event_data = $this->transaction_data_builder->buildParsedTransactionData($data);
        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for buildParsedTransactionData: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

        // fire an event
        if ($_debugLogTxTiming) { PHP_Timer::start(); }
        $this->events->fire('xchain.tx.received', [$event_data, 0, null, null]);
        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for fire xchain.tx.received: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

        // job successfully handled
        if ($_debugLogTxTiming) { PHP_Timer::start(); }
        $job->delete();
        if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for job->delete(): ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }
            
    }


}
