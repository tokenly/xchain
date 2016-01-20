<?php

namespace App\Console\Commands\Blocks;

use App\Commands\PruneTransactions;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReparseBlockCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:reparse-block';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reparses a block';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['block', InputArgument::REQUIRED, 'Block height or hash',],
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
        $block_height_or_hash = intval($this->argument('block'));
        $block_repository = app('App\Repositories\BlockRepository');
        if (is_numeric($block_height_or_hash)) {
            $blocks = $block_repository->findAllWithExactlyHeight($block_height_or_hash)->toArray();
            if (count($blocks) != 1) { throw new Exception("Found ".count($blocks)." with height {$block_height_or_hash}", 1); }
            $block = $blocks[0];
        } else {
            $block = $block_repository->findByHash($block_height_or_hash)->toArray();
        }
        $this->comment("Found block {$block['height']} ({$block['hash']})");


        // get the event data
        $event_builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinBlockEventBuilder');
        $event_data = $event_builder->buildBlockEventData($block['hash']);
        $this->comment("parsing block {$event_data['height']} ({$event_data['hash']})");
            
        // parse the block
        Event::fire('xchain.block.received', [$event_data]);

        $this->comment('done');
    }


}
