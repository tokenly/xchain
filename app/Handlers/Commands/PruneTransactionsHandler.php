<?php

namespace App\Handlers\Commands;

use App\Commands\PruneTransactions;
use App\Repositories\ProvisionalTransactionRepository;
use App\Repositories\TransactionRepository;
use Carbon\Carbon;
use Illuminate\Queue\InteractsWithQueue;

class PruneTransactionsHandler {

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct(TransactionRepository $transaction_repository, ProvisionalTransactionRepository $provisional_transaction_repository)
    {
        $this->transaction_repository             = $transaction_repository;
        $this->provisional_transaction_repository = $provisional_transaction_repository;
    }

    /**
     * Handle the command.
     *
     * @param  PruneTransactions  $command
     * @return void
     */
    public function handle(PruneTransactions $command)
    {
        $keep_seconds = $command->keep_seconds;

        if ($keep_seconds > 0) {
            $keep_date = Carbon::now()->subSeconds($keep_seconds);
            $this->provisional_transaction_repository->deleteOlderThan($keep_date);
            $this->transaction_repository->deleteOlderThan($keep_date);
        } else {
            $this->provisional_transaction_repository->deleteAll();
            $this->transaction_repository->deleteAll();
        }
    }

}
