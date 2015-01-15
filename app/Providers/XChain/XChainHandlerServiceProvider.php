<?php 

namespace App\Providers\XChain;

use Illuminate\Support\ServiceProvider;
use Nc\FayeClient\Adapter\CurlAdapter;
use Nc\FayeClient\Client as FayeClient;
use App\Pusher\Client as PusherClient;

class XChainHandlerServiceProvider extends ServiceProvider {

    protected $defer = true;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->bindPusherClient();

        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainWebsocketPusherHandler');
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainTransactionHandler');
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainBlockHandler');
    }


    // this is for the websocket pusher
    protected function bindPusherClient() {

        $this->app->bind('Nc\FayeClient\Client', function($app) {
            $client = new FayeClient(new CurlAdapter(), $app['config']['pusher.serverUrl'].'/public');
            return $client;
        });

        $this->app->bind('App\Pusher\Client', function($app) {
            $client = new PusherClient($app->make('Nc\FayeClient\Client'), $app['config']['pusher.password']);
            return $client;
        });
    }

}
