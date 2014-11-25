<?php 

namespace App\Providers\LogEntries;

use Illuminate\Support\ServiceProvider;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\LogEntriesHandler;

class LogEntriesServiceProvider extends ServiceProvider {


    public function register() {

        $token = $this->app['config']['logentries.token'];

        if ($token) {
            $handler = new LogEntriesHandler($token, $this->app['config']['logentries.ssl']);

            $formatter = new JsonFormatter();
            $handler->setFormatter($formatter);

            $this->app['log']->getMonolog()->pushHandler($handler);
        }
    }


}
