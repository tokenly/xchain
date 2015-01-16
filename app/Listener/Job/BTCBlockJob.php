<?php

namespace App\Listener\Job;

use Illuminate\Contracts\Events\Dispatcher;
use App\Listener\Builder\BlockEventBuilder;
use \Exception;

/*
* BTCBlockJob
*/
class BTCBlockJob
{
    public function __construct(BlockEventBuilder $event_builder, Dispatcher $events)
    {
        $this->event_builder        = $event_builder;
        $this->events               = $events;
    }

    public function fire($job, $data)
    {
        // build the event data
        $event_data = $this->event_builder->buildBlockEventFromXstalkerData($data);

        // fire an event
        $this->events->fire('xchain.block.received', [$event_data]);

        // job successfully handled
        $job->delete();
            
    }


}
