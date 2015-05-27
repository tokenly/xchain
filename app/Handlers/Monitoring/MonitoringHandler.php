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
        // check all queues
        $this->services_checker->pushQueueSizeChecks('xchainqueue', [
            'btcblock'                => 5,
            'btctx'                   => 50,
            'notifications_return'    => 50,
            'validate_counterpartytx' => 50,
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

    public function handleHTTPHealthCheck($check_type) {
        $anything_checked = false;

        if ($check_type == 'mysql' OR $check_type == 'all') {
            // check MySQL
            $this->services_checker->checkMySQLConnection();
            $anything_checked = true;
        }

        if ($check_type == 'queue' OR $check_type == 'all') {
            // check queue
            $this->services_checker->checkQueueConnection();
            $anything_checked = true;
        }

        if ($check_type == 'pusher' OR $check_type == 'all') {
            // check pusher
            $this->services_checker->checkPusherConnection();
            $anything_checked = true;
        }

        if ($check_type == 'xcpd' OR $check_type == 'all') {
            // check xcpd
            $this->services_checker->checkXCPDConnection();
            $anything_checked = true;
        }

        if ($check_type == 'bitcoind' OR $check_type == 'all') {
            // check bitcoind
            $this->services_checker->checkBitcoindConnection();
            $anything_checked = true;
        }

        if (!$anything_checked) { throw new Exception("Nothing checked for type {$check_type}", 1); }
    }

    public function subscribe($events) {
        $events->listen('consul-health.console.check', 'App\Handlers\Monitoring\MonitoringHandler@handleConsoleHealthCheck');
        $events->listen('consul-health.http.check', 'App\Handlers\Monitoring\MonitoringHandler@handleHTTPHealthCheck');
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Checks
    
}
