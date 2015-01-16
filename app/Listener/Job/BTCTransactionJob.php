<?php

namespace App\Listener\Job;

use Illuminate\Contracts\Events\Dispatcher;
use App\Listener\Builder\ParsedTransactionDataBuilder;
use \Exception;

/*
* BTCTransactionJob
*/
class BTCTransactionJob
{
    public function __construct(ParsedTransactionDataBuilder $transaction_data_builder, Dispatcher $events)
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
