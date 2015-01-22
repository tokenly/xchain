<?php

namespace App\Handlers\XChain\Network\Factory;

use App\Handlers\XChain\Network\Contracts\NetworkBlockHandler;
use App\Handlers\XChain\Network\Contracts\NetworkTransactionHandler;
use Exception;
use Illuminate\Contracts\Foundation\Application;

/**
 * This is invoked when a new block is received
 */
class NetworkHandlerFactory {

    public function __construct(Application $app) {
        $this->app = $app;
    }

    public function buildBlockHandler($network) {
        if (!strlen($network)) { throw new Exception("Network not provided when building BlockHandler", 1); }
        $uc_network = ucwords($network);
        $handler = $this->app->make("App\\Handlers\\XChain\\Network\\{$uc_network}\\{$uc_network}BlockHandler");
        if (!($handler instanceof NetworkBlockHandler)) { throw new Exception("Invalid handler for network $network", 1); }
        return $handler;
    }

    public function buildTransactionHandler($network) {
        if (!strlen($network)) { throw new Exception("Network not provided when building TransactionHandler", 1); }
        $uc_network = ucwords($network);
        $handler = $this->app->make("App\\Handlers\\XChain\\Network\\{$uc_network}\\{$uc_network}TransactionHandler");
        if (!($handler instanceof NetworkTransactionHandler)) { throw new Exception("Invalid handler for network $network", 1); }
        return $handler;
    }

}
