<?php

namespace App\Handlers\Commands;

use App\Commands\PruneBlocks;
use App\Repositories\BlockRepository;
use Illuminate\Queue\InteractsWithQueue;

class PruneBlocksHandler {

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct(BlockRepository $block_repository)
    {
        $this->block_repository = $block_repository;
    }

    /**
     * Handle the command.
     *
     * @param  PruneBlocks  $command
     * @return void
     */
    public function handle(PruneBlocks $command)
    {
        $keep_blocks = $command->keep_blocks;

        if ($keep_blocks > 0) {
            $this->block_repository->deleteAllBlocksExcept($keep_blocks);
        } else {
            $this->block_repository->deleteAll();
        }
    }

}
