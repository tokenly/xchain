<?php namespace App\Commands;

use App\Commands\Command;

class PruneTransactions extends Command {

    var $keep_seconds;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct($keep_seconds=7200)
    {
        $this->keep_seconds = $keep_seconds;
    }

}
