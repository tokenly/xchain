<?php 

namespace App\Providers\InfluxDB;

use Illuminate\Support\ServiceProvider;
use InfluxDB\ClientFactory;

class InfluxDBServiceProvider extends ServiceProvider {


    public function register() {
        $this->bindInfluxDBLogClient();
    }

    // this is for the websocket pusher
    protected function bindInfluxDBLogClient() {
        $this->app->bind('InfluxDB\Client', function($app) {
            $config = $app['config']['influxdb'];

            $options = [
                'adapter' => [
                    'name' => 'InfluxDB\\Adapter\\UdpAdapter',
                ],
                'options' => [
                    'host'     => $config['host'],
                    'port'     => $config['port'],
                    'username' => $config['username'],
                    'password' => $config['password'],
                ],
            ];
            // echo "\$options:\n".json_encode($options, 192)."\n";
            $client = ClientFactory::create($options);

            return $client;
        });
    }


}
