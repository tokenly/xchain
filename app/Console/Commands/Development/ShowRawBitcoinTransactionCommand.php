<?php

namespace App\Console\Commands\Development;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ShowRawBitcoinTransactionCommand extends Command {

    use DispatchesJobs;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-xchain:show-raw-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Loads a raw bitcoind transaction';



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
            ['save', 's', InputOption::VALUE_NONE, 'Save to API fixtures.'],
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
        $do_save = $this->option('save');
        $this->info("Loading $txid from bitcoind");

        $result = app('Nbobtc\Bitcoind\Bitcoind')->getrawtransaction($txid, true);
        $result = json_decode(json_encode($result), true);

        if ($do_save) {
            $filepath = base_path().'/tests/fixtures/api/_getrawtransaction_'.$txid.'.json';
            if (file_exists($filepath)) { throw new Exception("Filepath $filepath already exists.", 1); }
            file_put_contents($filepath, json_encode($result, 192));
            $this->info("file saved to $filepath");
        } else {
            if ($as_compact) {
                $this->line(json_encode($result));
            } else {
                $this->line(json_encode($result, 192));
            }
        }


        $this->comment('done');
    }


}
