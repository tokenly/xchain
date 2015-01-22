<?php

namespace App\Listener\Job;

use App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use \Exception;

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
        // build the event data
        $event_data = $this->transaction_data_builder->buildParsedTransactionData($data);

        // fire an event
        $this->events->fire('xchain.tx.received', [$event_data, 0, null, null]);

        // job successfully handled
        $job->delete();
            
    }


}
