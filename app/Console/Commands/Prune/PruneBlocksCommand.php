<?php

namespace App\Console\Commands\Prune;

use App\Commands\PruneBlocks;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class PruneBlocksCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:prune-blocks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prunes old blocks';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['blocks', InputArgument::OPTIONAL, 'Number of blocks to keep', 300],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            // ['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $blocks = intval($this->input->getArgument('blocks'));
        if ($blocks == 0) {
            $this->comment('Pruning all blocks.');
        } else {
            $this->comment('Pruning all but the last '.$blocks.' blocks.');
        }
        $this->dispatch(new PruneBlocks($blocks));
        $this->comment('done');
    }


}
