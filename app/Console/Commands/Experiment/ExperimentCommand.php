<?php

namespace App\Console\Commands\Experiment;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ExperimentCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-exp:expirement';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Used for experiments';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->comment("Begin experiment");

        // run your experiment here

        $this->comment("End experiment");
    }

}
