<?php

namespace App\Providers\EventLog;

use App\Providers\EventLog\EventLog;
use Illuminate\Support\ServiceProvider;

class EventLogServiceProvider extends ServiceProvider {


    public function register() {
        $this->app->bind('eventlog', function() {
            return new EventLog();
        });

    }


}
