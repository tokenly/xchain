<?php

namespace App\Repositories;

use App\Models\EventMonitor;
use Exception;
use Tokenly\LaravelApiProvider\Repositories\APIRepository;

/*
* EventMonitorRepository
*/
class EventMonitorRepository extends APIRepository
{

    protected $model_type = 'App\Models\EventMonitor';


    public function findByEventType($event_type_string) {
        return $this->prototype_model
            ->where('monitor_type_int', EventMonitor::typeStringToInteger($event_type_string))
            ->get();
    }

}
