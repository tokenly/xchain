<?php

namespace App\Handlers\Commands;

use App\Commands\PruneBlocks;
use App\Repositories\BlockRepository;
use App\Repositories\NotificationRepository;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class PruneBlocksHandler {

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct(BlockRepository $block_repository, NotificationRepository $notification_repository)
    {
        $this->block_repository        = $block_repository;
        $this->notification_repository = $notification_repository;
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

        DB::transaction(function() use ($keep_blocks) {

            // archive notifications so the old blocks can be cleared
            $this->archiveNotifications($keep_blocks);

            if ($keep_blocks > 0) {
                $this->block_repository->deleteAllBlocksExcept($keep_blocks);
            } else {
                $this->block_repository->deleteAll();
            }

        });

    }

    protected function archiveNotifications($keep_blocks) {
        foreach ($this->block_repository->findAllBlocksBefore($keep_blocks) as $block_to_archive) {
            foreach ($this->notification_repository->findByBlockId($block_to_archive['id']) as $notification) {
                $this->notification_repository->archive($notification);
            }
        }
    }

}
