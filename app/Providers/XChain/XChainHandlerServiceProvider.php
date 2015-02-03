<?php 

namespace App\Providers\XChain;

use Illuminate\Support\ServiceProvider;

class XChainHandlerServiceProvider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainWebsocketPusherHandler');
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainTransactionHandler');
        $this->app->make('events')->subscribe('App\Handlers\XChain\XChainBlockHandler');
    }


}
