<?php

namespace App\Providers\BlockingBeanstalkd;


use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\Jobs\BeanstalkdJob;
use Pheanstalk\Job as PheanstalkJob;

/**
 *  Overrides the default queue worker to add a blocking timeout
 *  This reduces the load on daemon processes with a timeout of zero
 */
class BlockingBeanstalkdQueue extends BeanstalkdQueue {

    protected $reserve_timeout = 2;

    /**
     * Pop the next job off of the queue.
     *
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        $job = $this->pheanstalk->watchOnly($queue)->reserve($this->reserve_timeout);

        if ($job instanceof PheanstalkJob)
        {
            return new BeanstalkdJob($this->container, $this->pheanstalk, $job, $queue);
        }
    }


}
