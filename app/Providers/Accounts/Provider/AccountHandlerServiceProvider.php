<?php

namespace App\Providers\Accounts\Provider;

use App\Providers\Accounts\AccountHandler;
use Exception;
use Illuminate\Support\ServiceProvider;

class AccountHandlerServiceProvider extends ServiceProvider {

    public function register() {
        $this->app->bind('accounthandler', function($app) {
            return new AccountHandler(app('App\Repositories\PaymentAddressRepository'), app('App\Repositories\AccountRepository'), app('App\Repositories\LedgerEntryRepository'));
        });

    }



    

}
