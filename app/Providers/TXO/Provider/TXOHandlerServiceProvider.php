<?php

namespace App\Providers\TXO\Provider;

use App\Providers\TXO\TXOHandler;
use Exception;
use Illuminate\Support\ServiceProvider;

class TXOHandlerServiceProvider extends ServiceProvider {

    public function register() {
        $this->app->bind('txohandler', function($app) {
            return new TXOHandler(app('App\Repositories\TXORepository'));
        });

    }



    

}
