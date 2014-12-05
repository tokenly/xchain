<?php 

namespace App\Providers\XChain;

use Illuminate\Support\ServiceProvider;
use Nc\FayeClient\Adapter\CurlAdapter;
use Nc\FayeClient\Client;

class XChainHandlerServiceProvider extends ServiceProvider {

    protected $defer = true;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->bindFayeClient();
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainWebsocketPusherHandler');
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainNotificationBuilderHandler');
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainBlockHandler');
    }


    // this is for the websocket pusher
    protected function bindFayeClient() {
        $this->app->bind('Nc\FayeClient\Client', function($app) {
            $client = new Client(new CurlAdapter(), $app['config']['pusher.serverUrl'].'/public');
            return $client;
        });
    }

}
