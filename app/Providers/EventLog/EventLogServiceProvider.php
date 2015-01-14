<?php

namespace App\Providers\EventLog;

use App\Providers\EventLog\EventLog;
use Illuminate\Support\ServiceProvider;

class EventLogServiceProvider extends ServiceProvider {


    public function register() {
        $this->app->bind('eventlog', function($app) {
            return new EventLog($app->make('InfluxDB\Client'));
        });

    }


}
