<?php 

namespace App\Providers\XChain\BlockingBeanstalkd;

use App\Providers\XChain\BlockingBeanstalkd\BlockingBeanstalkdConnector;
use Illuminate\Queue\QueueServiceProvider;

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
