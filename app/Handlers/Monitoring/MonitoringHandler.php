<?php

namespace App\Handlers\Monitoring;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tokenly\ConsulHealthDaemon\ConsulClient;

/**
 * This is invoked when a new block is received
 */
class MonitoringHandler {

    public function __construct(ConsulClient $consul_client) {
        $this->consul_client = $consul_client;
    }

    public function handleHealthCheck() {
        $queue_names = [
            'btcblock',
            'btctx',
            'notifications_return',
            'validate_counterpartytx',
        ];

        foreach($queue_names as $queue_name) {
            try {
                $queue_size = $this->getQueueSize($queue_name);

                $service_id = "xchainqueue_queue_".$queue_name;
                try {
                    if ($queue_size < 50) {
                        $this->consul_client->checkPass($service_id);
                    } else {
                        $this->consul_client->checkFail($service_id, "Queue $queue_name was $queue_size");
                    }
                } catch (Exception $e) {
                    Log::error($e->getMessage());
                }

            } catch (Exception $e) {
                try {
                    $this->consul_client->checkFail($service_id, $e->getMessage());
                } catch (Exception $e) {
                    Log::error($e->getMessage());
                }
            }

        }

        return;
    }

    public function getQueueSize($queue_name) {
        $pheanstalk = app('queue')->connection('blockingbeanstalkd')->getPheanstalk();
        $stats = $pheanstalk->statsTube($queue_name);
        return $stats['current-jobs-urgent'];
    }

    public function subscribe($events) {
        $events->listen('consul-health.check', 'App\Handlers\Monitoring\MonitoringHandler@handleHealthCheck');
    }

}
