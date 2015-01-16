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
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;


    public function boot()
    {
    }


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->package('tokenly/xchain-listener', 'xchain-listener', __DIR__.'/');

        $this->app->make('events')->subscribe('App\Listener\EventHandlers\ConsoleLogEventHandler');
    }



}
