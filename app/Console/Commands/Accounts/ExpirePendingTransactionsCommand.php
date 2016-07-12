<?php

namespace App\Console\Commands\Accounts;

use App\Models\PaymentAddress;
use App\Providers\Accounts\Facade\AccountHandler;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ExpirePendingTransactionsCommand extends Command {


    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:expire-pending-transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes ledger entries for timed out, unconfirmed transactions';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['payment-address-uuid', InputArgument::OPTIONAL, 'Payment Address UUID'],
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
            ['timeout',  't', InputOption::VALUE_OPTIONAL, 'Transaction expiration seconds', 14400],
            ['log',      'l', InputOption::VALUE_NONE, 'Log results to the event log'],
            ['progress', 'p', InputOption::VALUE_NONE, 'With progress'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $payment_address_repo          = app('App\Repositories\PaymentAddressRepository');
        $blockchain_balance_reconciler = app('App\Blockchain\Reconciliation\BlockchainBalanceReconciler');

        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
        $do_log               = !!$this->input->getOption('log');
        $with_progress        = !!$this->input->getOption('progress');
        $timeout_secs         = intval($this->input->getOption('timeout'));
        if ($timeout_secs < 1) { throw new Exception("Timeout seconds must be a number", 1); }

        if ($payment_address_uuid) {
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }
            $payment_addresses = collect([$payment_address]);
        } else {
            $payment_addresses = $payment_address_repo->findAll();
        }

        $start_time = time();
        $results = ['total' => 0, 'expired' => 0, 'runTime' => 0, 'modifiedAddresses' => [],];
        $total_count = $payment_addresses->count();
        $progress_bar = null;
        if ($with_progress) {
            $progress_bar = new ProgressBar(new ConsoleOutput(OutputInterface::VERBOSITY_VERY_VERBOSE), $total_count);
        }

        foreach($payment_addresses as $payment_address) {
            ++$results['total'];

            // find any pending transactions
            $timed_out_txids = DB::transaction(function() use ($payment_address, $timeout_secs) {
                $timed_out_txids = $this->findTimedOutTXIDs($payment_address, $timeout_secs);
                // echo "\$timed_out_txids: ".json_encode($timed_out_txids, 192)."\n";

                // clear the timed out ledger entries
                $this->clearTimedOutTXIDs($payment_address, $timed_out_txids);
                
                return $timed_out_txids;
            });

            if ($timed_out_txids) {
                $results['modifiedAddresses'][] = [
                    'address' => $payment_address['address'],
                    'id'      => $payment_address['id'],
                    'uuid'    => $payment_address['uuid'],
                    'managed' => $payment_address->isManaged()
                ];
            }
            if ($with_progress) { $progress_bar->advance(); }
        }

        if ($with_progress) {
            $progress_bar->finish();
            $this->line('');
        }


        $run_time = time() - $start_time;
        $results['runTime'] = $run_time;

        if ($do_log) {
            EventLog::log('expirePending.complete', $results, ['total', 'expired', 'runTime',]);
        }

        $this->info('done');
    }

    // ------------------------------------------------------------------------
    
    protected function findTimedOutTXIDs(PaymentAddress $payment_address, $timeout_secs) {

        $ledger = app('App\Repositories\LedgerEntryRepository');
        $transaction_store = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore');

        $default_account = AccountHandler::getAccount($payment_address);

        $timed_out_txids = [];

        // look for unconfirmed incoming transactions
        $results = $ledger->findUnreconciledTransactionEntries($default_account);
        foreach($results as $result) {
            $txid = $result['txid'];
            $ledger_entries = $ledger->findByTXID($txid, $payment_address['id'], $result['type']);
            $any_timed_out = false;
            foreach ($ledger_entries as $ledger_entry) {
                if ($ledger_entry['created_at']->getTimestamp() < (time() - $timeout_secs)) {
                    $any_timed_out = true;
                    break;
                }
            }

            if ($any_timed_out) {
                try {
                    $loaded_transaction = $transaction_store->getTransaction($txid);
                } catch (Exception $e) {
                    if ($e->getCode() != -5) {
                        EventLog::warning("bitcoind returned error: (".$e->getCode().") ".$e->getMessage());
                    }
                    $loaded_transaction = false;
                }
                if (!$loaded_transaction) {
                    // return all of the bad ledger entries
                    $timed_out_txids[$txid] = true;
                }
            }
        }

        return array_keys($timed_out_txids);

    }

    protected function clearTimedOutTXIDs(PaymentAddress $payment_address, $timed_out_txids) {
        $payment_address_id = $payment_address['id'];

        $ledger = app('App\Repositories\LedgerEntryRepository');
        foreach($timed_out_txids as $txid) {
            Log::debug("deleting entries for txid $txid for payment address {$payment_address['uuid']} ({$payment_address['address']})");
            $ledger->deleteByTXID($txid, $payment_address_id);
        }
    }

}
