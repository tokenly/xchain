<?php 

namespace App\Providers\XChain;

use App\Providers\XChain\BlockingBeanstalkd\BlockingBeanstalkdConnector;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\ServiceProvider;
use Nc\FayeClient\Adapter\CurlAdapter;
use Nc\FayeClient\Client;

class BlockingBeanstalkServiceProvider extends QueueServiceProvider {


    public function registerConnectors($manager) {
        parent::registerConnectors($manager);

        // add the blocking beanstalkd connector
        $manager->addConnector('blockingbeanstalkd', function()
        {
            return new BlockingBeanstalkdConnector();
        });
    }



}
