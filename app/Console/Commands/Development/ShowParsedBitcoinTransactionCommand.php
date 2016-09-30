<?php

namespace App\Console\Commands\Development;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ShowParsedBitcoinTransactionCommand extends Command {

    use DispatchesJobs;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-xchain:show-parsed-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Builds an parsed transaction';



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
        $bitcoin_data = $bitcoind_transaction_builder->buildTransactionData($txid);

        $ts = time() * 1000;
        $parsed_tx = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder')->buildParsedTransactionData($bitcoin_data, $ts);

        if ($as_compact) {
            $this->line(json_encode($parsed_tx));
        } else {
            $this->line(json_encode($parsed_tx, 192));
        }
        $this->comment('done');
    }


}
