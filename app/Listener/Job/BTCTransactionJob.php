<?php

namespace App\Listener\Job;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder;
use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHP_Timer;
use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;

/*
* BTCTransactionJob
*/
class BTCTransactionJob
{
    public function __construct(BitcoinTransactionEventBuilder $transaction_data_builder, BitcoinTransactionStore $bitcoin_transaction_store)
    {
        $this->transaction_data_builder  = $transaction_data_builder;
        $this->bitcoin_transaction_store = $bitcoin_transaction_store;
    }

    public function fire($job, $data)
    {
        try {

            $_debugLogTxTiming = Config::get('xchain.debugLogTxTiming');

            // load from bitcoind
            if ($_debugLogTxTiming) { Log::debug("Begin {$data['txid']}"); }
            if ($_debugLogTxTiming) { PHP_Timer::start(); }
            $transaction_model = $this->bitcoin_transaction_store->getParsedTransactionFromBitcoind($data['txid']);
            $bitcoin_transaction_data = $transaction_model['parsed_tx']['bitcoinTx'];
            if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for getParsedTransactionFromBitcoind: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

            // parse the transaction
            if ($_debugLogTxTiming) { PHP_Timer::start(); }
            $event_data = $this->transaction_data_builder->buildParsedTransactionData($bitcoin_transaction_data, $data['ts']);
            if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for buildParsedTransactionData: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

            // fire an event
            if ($_debugLogTxTiming) { PHP_Timer::start(); }
            Event::fire('xchain.tx.received', [$event_data, 0, null, null]);
            if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for fire xchain.tx.received: ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

            // job successfully handled
            if ($_debugLogTxTiming) { PHP_Timer::start(); }
            $job->delete();
            if ($_debugLogTxTiming) { Log::debug("[".getmypid()."] Time for job->delete(): ".PHP_Timer::secondsToTimeString(PHP_Timer::stop())); }

        } catch (Exception $e) {
            EventLog::logError('BTCTransactionJob.failed', $e, $data);
            throw $e;
        }
    }


}
