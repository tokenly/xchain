<?php

namespace App\Commands;

use App\Commands\Command;

class PruneBlocks extends Command {

    var $keep_blocks;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct($keep_blocks=300)
    {
        $this->keep_blocks = $keep_blocks;
    }

}
