<?php

namespace App\Console\Commands\TXO;

use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReconcileTXOsCommand extends Command {

    use DispatchesJobs;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchaintxo:reconcile-txos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconciles unspent transaction outputs';



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
            ['reconcile', 'r', InputOption::VALUE_NONE, 'Reconcile any differences'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $txo_repository            = app('App\Repositories\TXORepository');
        $payment_address_repo      = app('App\Repositories\PaymentAddressRepository');
        $bitcoin_payer             = app('Tokenly\BitcoinPayer\BitcoinPayer');
        $blockchain_txo_reconciler = app('App\Blockchain\Reconciliation\BlockchainTXOReconciler');

        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
        $should_reconcile     = $this->input->getOption('reconcile');

        if ($payment_address_uuid) {
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }
            $payment_addresses = [$payment_address];
        } else {
            $payment_addresses = $payment_address_repo->findAll();
        }

        $api_call = app('App\Repositories\APICallRepository')->create([
            'user_id' => $this->getConsoleUser()['id'],
            'details' => [
                'command' => 'xchaintxo:reconcile-txos',
                'args'    => ['payment_address' => $payment_address['uuid'], ],
            ],
        ]);


        foreach($payment_addresses as $payment_address) {
            Log::debug("reconciling TXOs for {$payment_address['address']} ({$payment_address['uuid']})");

            // build the differences
            $txo_differences = $blockchain_txo_reconciler->buildDifferences($payment_address);

            if ($txo_differences['any']) {
                $this->comment("Differences found for {$payment_address['address']} ({$payment_address['uuid']})");
                $this->line(json_encode($txo_differences, 192));
                $this->line('');

                if ($should_reconcile) {
                    // reconcile the differences
                    $this->comment("Reconciling");
                    $blockchain_txo_reconciler->reconcileDifferences($txo_differences, $payment_address, $api_call);
                }
            } else {
                $this->comment("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");
            }

        }

        $this->info('done');
    }


    // ------------------------------------------------------------------------
    
    protected function getConsoleUser() {
        $user_repository = app('App\Repositories\UserRepository');
        $user = $user_repository->findByEmail('console-user@tokenly.co');
        if ($user) { return $user; }

        $user_vars = [
            'email'    => 'console-user@tokenly.co',
        ];
        $user = $user_repository->create($user_vars);


        return $user;
    }

}
