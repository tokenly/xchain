<?php

namespace App\Handlers\Monitoring;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\ConsulHealthDaemon\ServicesChecker;

/**
 * This is invoked when a new block is received
 */
class MonitoringHandler {

    public function __construct(ServicesChecker $services_checker) {
        $this->services_checker = $services_checker;
    }

    public function handleConsoleHealthCheck() {
        if (env('PROCESS_NAME', 'xchain') == 'xchainqueue') {
            $this->handleConsoleHealthCheckForXchainQueue();
        } else {
            $this->handleConsoleHealthCheckForXchain();
        }
    }

    public function handleConsoleHealthCheckForXchainQueue() {
        // check all queues
        $this->services_checker->checkQueueSizes([
            'btcblock'                => 5,
            'btctx'                   => 50,
            'notifications_return'    => 50,
            'validate_counterpartytx' => 50,
        ]);

        // check all queues
        $this->services_checker->checkTotalQueueJobsVelocity([
            'btcblock'             => [1,  '2 hours'],
            'btctx'                => [10, '1 minute'],
            'notifications_out'    => [1,  '2 hours'],
            'notifications_return' => [1,  '2 hours'],
        ]);

        // check MySQL
        $this->services_checker->checkMySQLConnection();

        // check pusher
        $this->services_checker->checkPusherConnection();

        // check xcpd
        $this->services_checker->checkXCPDConnection();

        // check bitcoind
        $this->services_checker->checkBitcoindConnection();
    }

    public function handleConsoleHealthCheckForXchain() {
        // check queue
        $this->services_checker->checkQueueConnection();

        // check MySQL
        $this->services_checker->checkMySQLConnection();

        // check pusher
        $this->services_checker->checkPusherConnection();

        // check xcpd
        $this->services_checker->checkXCPDConnection();

        // check bitcoind
        $this->services_checker->checkBitcoindConnection();
    }

    public function subscribe($events) {
        $events->listen('consul-health.console.check', 'App\Handlers\Monitoring\MonitoringHandler@handleConsoleHealthCheck');
        $events->listen('consul-health.http.check', 'App\Handlers\Monitoring\MonitoringHandler@handleHTTPHealthCheck');
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Checks
    
}
