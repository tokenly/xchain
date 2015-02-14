<?php

namespace App\Jobs\XChain;

use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;

/*
* RetryingJob
*/
abstract class RetryingJob
{

    public function fire($job, $data)
    {
        // update the notification
        // jobData.return = {
        //     result: success
        //     err: err
        //     timestamp: new Date().getTime()
        // }

        try {
            // attempt job
            $this->fireJob($job, $data);

            // job successfully handled
            $job->delete();

        } catch (Exception $e) {
            EventLog::logError('job.failed', $e);

            if ($job->attempts() > 30) {
                // give up
                EventLog::logError('job.failed.permanent', $data['meta']['id']);
                $job->delete();

            } else if ($job->attempts() > 10) {
                // try a 30 second delay
                $job->release(30);

            } else if ($job->attempts() > 1) {
                // try a 10 second delay
                $job->release(10);
            }
        }

            
    }

    abstract public function fireJob($job, $data);


}
