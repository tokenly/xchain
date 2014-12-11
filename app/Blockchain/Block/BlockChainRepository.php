<?php

namespace App\Blockchain\Block;

use App\Repositories\BlockRepository;
use Exception;

/*
* BlockChainRepository
* A decorator of the block repository
*/
class BlockChainRepository {

    public function __construct(BlockRepository $block_repository) {
        $this->block_repository       = $block_repository;
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
