<?php

namespace App\Jobs;

use App\Repositories\ProvisionalTransactionRepository;
use App\Repositories\TransactionRepository;
use Carbon\Carbon;
use Illuminate\Queue\InteractsWithQueue;

class PruneTransactionsJob {

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

    /**
     * Handle the command.
     *
     * @param  PruneTransactions  $command
     * @return void
     */
    public function handle(TransactionRepository $transaction_repository, ProvisionalTransactionRepository $provisional_transaction_repository)
    {
        $keep_seconds = $this->keep_seconds;

        if ($keep_seconds > 0) {
            $keep_date = Carbon::now()->subSeconds($keep_seconds);
            $provisional_transaction_repository->deleteOlderThan($keep_date);
            $transaction_repository->deleteOlderThan($keep_date);
        } else {
            $provisional_transaction_repository->deleteAll();
            $transaction_repository->deleteAll();
        }
    }

}
