<?php

namespace App\Blockchain\Block;

use App\Repositories\BlockRepository;
use Exception;
use Illuminate\Support\Facades\Config;
use Tokenly\Insight\Client;
use App\Listener\Builder\BlockEventBuilder;

/*
* BlockChainStore
* A decorator of the block repository
*/
class BlockChainStore {

    public function __construct(BlockRepository $block_repository, Client $insight_client, BlockEventBuilder $block_event_builder) {
        $this->block_repository    = $block_repository;
        $this->insight_client      = $insight_client;
        $this->block_event_builder = $block_event_builder;
    }

    public function findAllAsOfHeightEndingWithBlockhash($height, $ending_block_hash) {
        $blocks_by_hash = [];
        foreach ($this->block_repository->findAllAsOfHeight($height) as $block_model) {
            $blocks_by_hash[$block_model['hash']] = $block_model;
        }

        $working_hash = $ending_block_hash;
        $last_item_first_chain = [];
        while (isset($blocks_by_hash[$working_hash])) {
            $found_block = $blocks_by_hash[$working_hash];
            $last_item_first_chain[] = $found_block;
            $working_hash = $found_block['parsed_block']['previousblockhash'];
            if (!$working_hash) { throw new Exception("Unexpected previousblockhash: $working_hash", 1); }
        }

        return array_reverse($last_item_first_chain);
    }

    public function findFirstMissingHash($start_hash, $backfill_max=null) {
        if ($backfill_max === null) { $backfill_max = Config::get('xchain.backfill_max'); }

        $working_hash = $start_hash;
        $blocks_found = 0;

        while ($blocks_found < $backfill_max) {
            $block_model = $this->findByHash($working_hash);
            $block = $block_model ? $block_model['parsed_block'] : null;
            ++$blocks_found;

            if (!$block) {
                return $working_hash;
            } else {
                $working_hash = $block['previousblockhash'];
            }
        }

        return null;
    }

    public function loadMissingBlockEventsFromInsight($first_missing_hash, $backfill_max=null) {
        if ($backfill_max === null) { $backfill_max = Config::get('xchain.backfill_max'); }

        $missing_block_events = [];

        $any_found = false;
        $working_hash = $first_missing_hash;
        $blocks_found = 0;

        while (!$any_found AND $blocks_found < $backfill_max) {
            $block_model = $this->findByHash($working_hash);
            $block = $block_model['parsed_block'];
            ++$blocks_found;

            if ($block) {
                $any_found = true;
                $working_hash = null;
                // we are done
            } else {
                // load the block from insight
                $block = $this->loadBlockEventFromInsight($working_hash);

                $missing_block_event = $block;
                $missing_block_events[] = $missing_block_event;
                $working_hash = $block['previousblockhash'];
            }
        }

        // now reverse the missing blocks so the oldest one is processed first
        $missing_block_events = array_reverse($missing_block_events);

        return $missing_block_events;

    }

    public function loadBlockEventFromInsight($hash) {
        $insight_data = $this->insight_client->getBlock($hash);
        if (!$insight_data) { return null; }
        return $this->block_event_builder->buildBlockEventFromInsightData($insight_data);
    }

    /**
     * Delegate to the block repository
     * @param  string $method method to call on the block repository
     * @param  array  $args   argumens
     * @return mixed returned value from the repository
     */
    public function __call($method, $args) {
        return call_user_func_array([$this->block_repository, $method], $args);
    }


}
