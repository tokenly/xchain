<?php

namespace App\Listener\Job;

use App\Handlers\XChain\Network\Bitcoin\BitcoinBlockEventBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use \Exception;

/*
* BTCBlockJob
*/
class BTCBlockJob
{
    public function __construct(BitcoinBlockEventBuilder $event_builder, Dispatcher $events)
    {
        $this->event_builder        = $event_builder;
        $this->events               = $events;
    }

    public function fire($job, $data)
    {

        // build the event data
        $event_data = $this->event_builder->buildBlockEventFromXstalkerData($data);

        // fire an event
        try {
            $this->events->fire('xchain.block.received', [$event_data]);
            
        } catch (Exception $e) {
            Log::error("BTCBlockJob xchain.block.received {$data['block']['height']} {$data['block']['hash']} ERROR: ".$e->getMessage());
            throw $e;
        }

        // job successfully handled
        $job->delete();
            
    }


}
