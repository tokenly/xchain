<?php

namespace App\Blockchain\Block;

use App\Repositories\BlockRepository;
use Exception;
use Illuminate\Support\Facades\Log;
use Nbobtc\Bitcoind\Bitcoind;
use Tokenly\LaravelEventLog\Facade\EventLog;

/*
* ConfirmationsBuilder
* A decorator of the block repository
*/
class ConfirmationsBuilder {

    public function __construct(BlockRepository $block_repository, Bitcoind $bitcoind_client) {
        $this->block_repository = $block_repository;
        $this->bitcoind_client  = $bitcoind_client;

    }

    public function findLatestBlockHeight() {
        return $this->block_repository->findLatestBlockHeight();
    }

    public function getConfirmationsForBlockHashAsOfHeight($hash, $as_of_height) {
        $block = $this->block_repository->findByHash($hash);
        // Log::debug("getConfirmationsForBlockHashAsOfHeight \$block=".json_encode($block, 192));
        if ($block) {
            $block_height = $block['height'];
        } else {
            $block_height = $this->getBlockHeightFromBlockHashFromBitcoind($hash);
        }
        if (!$block_height) { return null; }
        
        return $as_of_height - $block_height + 1;
    }


    protected function getBlockHeightFromBlockHashFromBitcoind($hash) {
        $block = $this->bitcoind_client->getblock($hash);

        // convert to array
        $block = json_decode(json_encode($block), true);
        // Log::debug("getBlockHeightFromBlockHashFromBitcoind \$block=".json_encode($block, 192));

        if (!$block OR !is_array($block) OR !isset($block['height'])) {
            EventLog::logError('bitcoinBlock.error', "Failed to load block", ['hash' => $hash, 'blockData' => $block]);
        }

        return $block['height'];
    }

}
