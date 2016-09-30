<?php

namespace App\Jobs;

use App\Repositories\BlockRepository;
use App\Repositories\NotificationRepository;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class PruneBlocksJob {

    var $keep_blocks;

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct($keep_blocks=300)
    {
        $this->keep_blocks = $keep_blocks;
    }

    /**
     * Handle the command.
     *
     * @return void
     */
    public function handle(BlockRepository $block_repository, NotificationRepository $notification_repository)
    {
        $keep_blocks = $this->keep_blocks;

        DB::transaction(function() use ($keep_blocks, $block_repository, $notification_repository) {

            // archive notifications so the old blocks can be cleared
            $this->archiveNotifications($keep_blocks, $block_repository, $notification_repository);

            if ($keep_blocks > 0) {
                $block_repository->deleteAllBlocksExcept($keep_blocks);
            } else {
                $block_repository->deleteAll();
            }

        });

    }

    protected function archiveNotifications($keep_blocks, $block_repository, $notification_repository) {
        foreach ($block_repository->findAllBlockIDsBefore($keep_blocks) as $block_id) {
            foreach ($notification_repository->findByBlockId($block_id) as $notification) {
                $notification_repository->archive($notification);
            }
        }
    }

}
