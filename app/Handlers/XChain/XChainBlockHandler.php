<?php

namespace App\Handlers\XChain;

use App\Handlers\XChain\Network\Factory\NetworkHandlerFactory;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHP_Timer;
use Tokenly\LaravelEventLog\Facade\EventLog;

/**
 * This is invoked when a new block is received
 */
class XChainBlockHandler {

    public function __construct(NetworkHandlerFactory $network_handler_factory) {
        $this->network_handler_factory = $network_handler_factory;
    }

    public function handleNewBlock($block_event) {
        $block_handler = $this->network_handler_factory->buildBlockHandler($block_event['network']);

        PHP_Timer::start();
        $result = $block_handler->handleNewBlock($block_event);
        $float_seconds = PHP_Timer::stop();
        EventLog::info('block.finished', ['height' => $block_event['height'], 'time' => $float_seconds]);
        if ($_debugLogTxTiming = Config::get('xchain.debugLogTxTiming')) { Log::debug("[".getmypid()."] Time for handleNewBlock: ".PHP_Timer::secondsToTimeString($float_seconds)); }

        return $result;
    }

    public function processBlock($block_event) {
        $block_handler = $this->network_handler_factory->buildBlockHandler($block_event['network']);
        return $block_handler->processBlock($block_event);
    }


    public function subscribe($events) {
        $events->listen('xchain.block.received', 'App\Handlers\XChain\XChainBlockHandler@handleNewBlock');
    }

}
