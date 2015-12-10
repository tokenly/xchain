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

    const MAX_ATTEMPTS = 10;
    const RETRY_DELAY  = 6;

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

            // job successfully handled
            $job->delete();
        } catch (Exception $e) {
            EventLog::logError('BTCBlockJob.failed', $e, $data);

            // this block had a problem
            //   but it might be found if we try a few more times
            $attempts = $job->attempts();
            if ($attempts > self::MAX_ATTEMPTS) {
                // we've already tried MAX_ATTEMPTS times - give up
                Log::debug("Block {$data['hash']} event failed after attempt ".$attempts.". Giving up.");
                $job->delete();
            } else {
                $release_time = 2;
                Log::debug("Block {$data['hash']} event failed after attempt ".$attempts.". Trying again in ".self::RETRY_DELAY." seconds.");
                $job->release(self::RETRY_DELAY);
            }
        }

    }


}
