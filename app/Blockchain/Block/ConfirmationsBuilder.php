<?php

namespace App\Blockchain\Block;

use App\Repositories\BlockRepository;
use Exception;

/*
* ConfirmationsBuilder
* A decorator of the block repository
*/
class ConfirmationsBuilder {

    public function __construct(BlockRepository $block_repository) {
        $this->block_repository       = $block_repository;
    }

    public function findLatestBlockHeight() {
        return $this->block_repository->findLatestBlockHeight();
    }

    public function getConfirmationsForBlockHashAsOfHeight($hash, $as_of_height) {
        $block = $this->block_repository->findByHash($hash);
        if (!$block) { return null; }
        return $as_of_height - $block['height'] + 1;
    }


}
