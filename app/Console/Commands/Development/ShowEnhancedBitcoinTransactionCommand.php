<?php

namespace App\Console\Commands\Development;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ShowEnhancedBitcoinTransactionCommand extends Command {

    use DispatchesJobs;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-xchain:show-enhanced-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Builds an enhanced transaction';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['txid', InputArgument::REQUIRED, 'Transaction ID',],
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
            ['compact', 'c', InputOption::VALUE_NONE, 'Show in compact JSON.'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $txid = $this->argument('txid');
        $as_compact = $this->option('compact');
        $this->info("Loading $txid from bitcoind");

        $bitcoind_transaction_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $tx_data = $bitcoind_transaction_builder->buildTransactionData($txid);
        if ($as_compact) {
            $this->line(json_encode($tx_data));
        } else {
            $this->line(json_encode($tx_data, 192));
        }
        $this->comment('done');
    }


}
