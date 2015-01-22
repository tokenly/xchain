<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use \Exception;

/*
* BitcoinBlockEventBuilder
*/
class BitcoinBlockEventBuilder
{
    public function __construct()
    {
    }

    public function buildBlockEventFromXstalkerData($xstalker_data)
    {
        return $this->buildBlockEventFromInsightData($xstalker_data['block']);
    }

    public function buildBlockEventFromInsightData($block) {
        try {
            $event_data = [];

            $event_data['network']           = 'bitcoin';

            $event_data['hash']              = $block['hash'];
            $event_data['height']            = $block['height'];
            $event_data['previousblockhash'] = $block['previousblockhash'];
            $event_data['time']              = $block['time'];
            $event_data['tx']                = $block['tx'];

            return $event_data;

        } catch (Exception $e) {
            print "ERROR: ".$e->getMessage()."\n";
            echo "\$event_data:\n".json_encode($event_data, 192)."\n";
            throw $e;
        }

    }

}
