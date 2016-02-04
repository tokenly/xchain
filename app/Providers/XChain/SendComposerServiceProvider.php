<?php

namespace App\Providers\XChain;

use App\Blockchain\Composer\SendComposer;
use Illuminate\Support\ServiceProvider;

class SendComposerServiceProvider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        $this->app->bind('App\Blockchain\Composer\SendComposer', function($app) {
            return new SendComposer(app('App\Repositories\SendRepository'), app('App\Blockchain\Sender\PaymentAddressSender'));
        });

    }


}
