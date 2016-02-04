<?php

namespace App\Console\Commands\Development;

use App\Models\Transaction;
use App\Repositories\NotificationRepository;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReindexTransactionAddressesCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-xchain:reindex-transaction-addresses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindexes transaction addresses';


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            // ['block-height', InputArgument::REQUIRED, 'block height to start with'],
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
            ['limit', 'l', InputOption::VALUE_REQUIRED, 'limit number of transaction', 12000],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $limit = $this->option('limit');
        $this->comment('Processing '.$limit.' transactions');

        $transaction_repository = app('App\Repositories\TransactionRepository');

        foreach (Transaction::orderBy('id', 'desc')->limit($limit)->get() as $transaction) {
            $transaction_repository->refreshTransactionLookupEntries($transaction);
        }

        $this->info('done');
    }

}
