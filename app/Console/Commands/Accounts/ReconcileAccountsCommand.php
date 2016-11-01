<?php

namespace App\Console\Commands\Accounts;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Util\ArrayToTextTable;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LinusU\Bitcoin\AddressValidator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReconcileAccountsCommand extends Command {
    use DispatchesJobs;

    const CACHE_TTL = 43200; // 30 days (in minutes)

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:reconcile-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconciles account balances';



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
            ['compact',     'c', InputOption::VALUE_NONE,     'Output only the unreconciled address uuid'],
            ['email',       'e', InputOption::VALUE_OPTIONAL, 'Email the results'],
            ['log',         'l', InputOption::VALUE_NONE,     'Log results to the event log'],
            ['progress',    'p', InputOption::VALUE_NONE,     'With progress'],
            ['clear-cache', '',  InputOption::VALUE_NONE,     'Clear changed cache'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $ledger                        = app('App\Repositories\LedgerEntryRepository');
        $payment_address_repo          = app('App\Repositories\PaymentAddressRepository');
        $blockchain_balance_reconciler = app('App\Blockchain\Reconciliation\BlockchainBalanceReconciler');

        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
        $is_compact           = !!$this->input->getOption('compact');
        $email                = $this->input->getOption('email');
        $for_email            = !!$email;
        $do_log               = !!$this->input->getOption('log');
        $with_progress        = !!$this->input->getOption('progress');
        $clear_cache          = !!$this->input->getOption('clear-cache');

        if ($clear_cache) {
            $this->clearChangedCache();
        }

        if ($payment_address_uuid) {
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }
            $payment_addresses = collect([$payment_address]);
        } else {
            $payment_addresses = $payment_address_repo->findAll();
        }

        $start_time = time();
        $results = ['total' => 0, 'reconciled' => 0, 'unreconciled' => 0, 'runTime' => 0, 'unreconciledAddresses' => [], 'errors' => [],];
        $total_count = $payment_addresses->count();
        $progress_bar = null;
        if ($with_progress) {
            $progress_bar = new ProgressBar(new ConsoleOutput(OutputInterface::VERBOSITY_VERY_VERBOSE), $total_count);
            // $progress_bar->setOverwrite(false);
        }
        foreach($payment_addresses as $payment_address) {
            Log::debug("reconciling accounts for {$payment_address['address']} ({$payment_address['uuid']})");
            ++$results['total'];

            try {
                // compare
                $differences = $blockchain_balance_reconciler->buildDifferences($payment_address);
                if ($differences['any']) {
                    $results['unreconciledAddresses'][] = [
                        'address'     => $payment_address['address'],
                        'id'          => $payment_address['id'],
                        'uuid'        => $payment_address['uuid'],
                        'managed'     => $payment_address->isManaged(),
                        'differences' => $differences,
                    ];
                    ++$results['unreconciled'];
                    Log::debug("Found differences for {$payment_address['address']}");

                    if (!$for_email) {
                        if ($is_compact) {
                            $this->line($payment_address['uuid']);
                        } else {
                            if ($with_progress) { $this->line(''); }
                            $this->comment("Differences found for {$payment_address['address']} ({$payment_address['uuid']})");
                            $this->line($this->formatDifferencesForOutput($differences));
                            // $this->line(json_encode($differences, 192));
                            $this->line('');
                        }
                    }
                } else {
                    ++$results['reconciled'];
                    Log::debug("no differences for {$payment_address['address']} ({$payment_address['uuid']})");

                    if ($payment_address_uuid) {
                        // only showing one address
                        $this->comment("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");

                    }
                }
            } catch (Exception $e) {
                $results['errors'][] = [
                    'address' => $payment_address['address'],
                    'id'      => $payment_address['id'],
                    'uuid'    => $payment_address['uuid'],
                    'error'   => $e->getMessage(),
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
            $was_reconciled = ($results['unreconciled'] <= 0);
            if ($was_reconciled) {
                EventLog::log('reconciliation.success', $results);
            } else {
                EventLog::warning('reconciliation.unreconciled', $results, ['total', 'reconciled', 'unreconciled','runTime',]);
            }
        }

        if ($for_email) {
            $changed_unreconciled_addresses = $this->applyCachedRecentUnreconciledAddresses($results['unreconciledAddresses']);
            if ($changed_unreconciled_addresses) {
                $changed_unreconciled_addresses_count = count($changed_unreconciled_addresses);
                $date = Carbon::now()->toDayDateTimeString();
                $summary = "{$results['unreconciled']} unreconciled account".($results['unreconciled'] == 1 ? '' : 's')." with {$changed_unreconciled_addresses_count} new";
                $unreconciled_addresses = implode("\n", array_column($changed_unreconciled_addresses, 'address'));
                $mail_body = "There were {$summary}.\n\nThe unreconciled addresses are:\n$unreconciled_addresses\n\n$date\n";

                $changed_report = $this->buildReportForEmail($changed_unreconciled_addresses);
                $mail_body .= "\n-- Changed Report --\n\n";
                $mail_body .= $changed_report;

                Mail::raw($mail_body, function($message) use ($email, $date, $summary, $results) {
                    $message
                        ->to($email)
                        ->subject('['.env('APP_SERVER','unknown').'] XChain Reconciliation Report for '.$date.'- '.$summary)
                        ->from('no-replay@tokenly.com', 'XChain Reconciler')
                        ->attachData(json_encode($results, 192), 'unreconciledAddresses.json', ['mime' => 'application/json'])
                        ;
                });
            } else {
                EventLog::log('reconciliation.none', []);
            }

        }

        $this->info('done');
    }

    protected function formatDifferencesForOutput($differences) {
        $out = '';
        $f = function($raw) {
            if ($raw == '[NULL]' OR $raw === null) { return 0; }
            return floatval($raw);
        };
        foreach ($differences['differences'] as $asset => $difference_entry) {
            $diff_float = $f($difference_entry['xchain']) - $f($difference_entry['daemon']);
            $diff_text = CurrencyUtil::valueToFormattedString($diff_float);
            $width = strlen($diff_text);

            if (strlen($out)) { $out .= "\n"; }
            $out .= "     Asset: $asset\n";
            $out .= "    XChain: {$difference_entry['xchain']}\n";
            $out .= "Blockchain: {$difference_entry['daemon']}\n";
            // $out .= "------------".str_repeat('-', $width)."\n";
            $out .= "            ".str_repeat('-', $width)."\n";
            $out .= "Difference: {$diff_text}\n";
        }

        return $out;
    }

    protected function clearChangedCache() {
        $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
        $payment_addresses    = $payment_address_repo->findAll();
        $this->info('begin clearing cache');
        foreach($payment_addresses as $payment_address) {
            $cache_key = 'unreconciled.'.$payment_address['uuid'];
            Cache::forget($cache_key);
        }
        $this->info('end clearing cache');
    }

    protected function applyCachedRecentUnreconciledAddresses($all_unreconciled_addresses) {
        $filtered_uncreconciled = [];

        foreach($all_unreconciled_addresses as $unreconciled_address_entry) {
            $cache_key = 'unreconciled.'.$unreconciled_address_entry['uuid'];
            $cached_hash = Cache::get($cache_key);
            $actual_hash = md5(json_encode($unreconciled_address_entry['differences']));

            if (!$cached_hash OR $actual_hash != $cached_hash) {
                $filtered_uncreconciled[] = $unreconciled_address_entry;

                // store to the cache
                Cache::put($cache_key, $actual_hash, self::CACHE_TTL);
            }
        }

        return $filtered_uncreconciled;
    }

    protected function buildReportForEmail($unreconciled_addresses) {
        // 'address'     => $payment_address['address'],
        // 'id'          => $payment_address['id'],
        // 'uuid'        => $payment_address['uuid'],
        // 'managed'     => $payment_address->isManaged(),
        // 'differences' => $differences,

        $out = "";
        foreach($unreconciled_addresses as $unreconciled_address) {
            $out .= "   Address: {$unreconciled_address['address']} ({$unreconciled_address['uuid']})\n";
            $out .= $this->formatDifferencesForOutput($unreconciled_address['differences'])."\n";
            $out .= "\n";
            $out .= str_repeat('-', 82)."\n";
        }
        return $out;
    }

}
