<?php

namespace App\Listener\Job;

use App\Handlers\XChain\Network\Bitcoin\BitcoinBlockEventBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;

/*
* BTCBlockJob
*/
class BTCBlockJob
{
    public function __construct(BitcoinBlockEventBuilder $event_builder)
    {
        $this->event_builder = $event_builder;
    }

    public function fire($job, $data)
    {

        // build the event data
        $event_data = $this->event_builder->buildBlockEventData($data['hash']);

        // fire an event
        try {
            Event::fire('xchain.block.received', [$event_data]);
            
        } catch (Exception $e) {
            EventLog::logError('BTCBlockJob.failed', $e, $data);
            throw $e;
        }

        // job successfully handled
        $job->delete();

    }


}
