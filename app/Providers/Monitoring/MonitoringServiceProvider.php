<?php

namespace App\Providers\Monitoring;

use Illuminate\Support\ServiceProvider;

class MonitoringServiceProvider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->make('events')->subscribe('App\Handlers\Monitoring\MonitoringHandler');
    }


}
