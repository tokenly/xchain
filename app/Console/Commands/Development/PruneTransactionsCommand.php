<?php

namespace App\Console\Commands\Development;

use App\Commands\PruneTransactions;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class PruneTransactionsCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:prune-transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prunes old transactions';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['time', InputArgument::OPTIONAL, 'Time in seconds to prune', 36000],
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
        $seconds = intval($this->input->getArgument('time'));
        if ($seconds == 0) {
            $this->comment('Pruning all transactions.');
        } else {
            $this->comment('Pruning all transactions except those in the last '.$seconds.' seconds.');
        }
        $this->dispatch(new PruneTransactions($seconds));
        $this->comment('done');
    }


}
