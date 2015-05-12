<?php

namespace App\Listener;

use Illuminate\Support\ServiceProvider;
use \Exception;

/*
* XChainListenerServiceProvider
*/
class XChainListenerServiceProvider extends ServiceProvider
{


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->make('events')->subscribe('App\Listener\EventHandlers\ConsoleLogEventHandler');
    }



}
