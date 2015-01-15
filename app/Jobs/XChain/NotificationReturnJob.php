<?php

namespace App\Jobs\XChain;

use App\Jobs\XChain\RetryingJob;
use App\Providers\EventLog\Facade\EventLog;
use App\Repositories\NotificationRepository;
use Illuminate\Contracts\Events\Dispatcher;
use \Exception;

/*
* NotificationReturnJob
*/
class NotificationReturnJob extends RetryingJob
{
    public function __construct(NotificationRepository $notification_repository, Dispatcher $events)
    {
        $this->notification_repository = $notification_repository;
        $this->events                  = $events;
    }

    public function fireJob($job, $data) {
        $this->notification_repository->updateByUuid($data['meta']['id'], [
            'returned' => new \DateTime(),
            'status'   => ($data['return']['success'] ? 'success' : 'failure'),
            'error'    => $data['return']['error'],
            'attempts' => $data['return']['totalAttempts'],
        ]);

        // fire an event
        $this->events->fire('xchain.notification.returned', [$data]);
    }


}
