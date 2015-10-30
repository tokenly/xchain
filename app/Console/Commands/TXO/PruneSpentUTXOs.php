<?php

namespace App\Console\Commands\TXO;

use App\Commands\PruneTransactions;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class PruneSpentUTXOs extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchaintxo:prune-spent-txos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prunes old spent UTXOs';



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
        $txo_repository = app('App\Repositories\TXORepository');

        $keep_seconds = intval($this->input->getArgument('time'));
        if ($keep_seconds == 0) {
            $this->comment('Pruning all spent txos.');
        } else {
            $this->comment('Pruning all spent txos except those in the last '.$keep_seconds.' seconds.');
        }

        if ($keep_seconds > 0) {
            $keep_date = Carbon::now()->subSeconds($keep_seconds);
            $txo_repository->deleteSpentOlderThan($keep_date);
        } else {
            $txo_repository->deleteAll();
        }

        $this->comment('done');
    }


}
