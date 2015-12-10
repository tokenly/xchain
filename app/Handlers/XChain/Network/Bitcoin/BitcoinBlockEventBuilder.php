<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use Nbobtc\Bitcoind\Bitcoind;
use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;

/*
* BitcoinBlockEventBuilder
*/
class BitcoinBlockEventBuilder
{
    public function __construct(Bitcoind $bitcoind)
    {
        $this->bitcoind = $bitcoind;
    }


    public function buildBlockEventData($block_hash) {
        try {
            // get the full block data from bitcoind 
            $block = $this->bitcoind->getblock($block_hash, true);
            if (!$block) { throw new Exception("Block not found for hash $block_hash", 1); }

            // convert to array
            $block = json_decode(json_encode($block), true);

            // create the event data
            $event_data = [];

            $event_data['network']           = 'bitcoin';
            $event_data['hash']              = $block['hash'];
            $event_data['height']            = $block['height'];
            $event_data['previousblockhash'] = $block['previousblockhash'];
            $event_data['time']              = $block['time'];
            $event_data['tx']                = $block['tx'];

            return $event_data;

        } catch (Exception $e) {
            EventLog::logError('block', $e, ['hash' => $block_hash]);
            throw $e;
        }

    }

}
