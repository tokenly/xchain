<?php

namespace App\Console\Commands\Address;

use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\PaymentAddressArchiveRepository;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ResurrectAddressCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:resurrect-address';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restores an archived payment address';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['payment-address-uuid', InputArgument::REQUIRED, 'Payment Address UUID'],
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
            // ['inactive', 'i', InputOption::VALUE_NONE, 'Include inactive accounts'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        DB::transaction(function() {
            $payment_address_archive_repo = app('App\Repositories\PaymentAddressArchiveRepository');
            $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
            $blockchain_balance_reconciler = app('App\Blockchain\Reconciliation\BlockchainBalanceReconciler');
            $blockchain_txo_reconciler = app('App\Blockchain\Reconciliation\BlockchainTXOReconciler');

            $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
            $archived_payment_address = $payment_address_archive_repo->findByUuid($payment_address_uuid);
            if (!$archived_payment_address) { throw new Exception("Archived Payment Address not found", 1); }

            $api_call = app('App\Repositories\APICallRepository')->create([
                'user_id' => $this->getConsoleUser()['id'],
                'details' => [
                    'command' => 'xchain:resurrect-address',
                    'args'    => ['payment_address' => $payment_address_uuid, ],
                ],
            ]);

            $payment_address = \App\Models\PaymentAddress::create($archived_payment_address->getAttributes());
            // $payment_address = $payment_address_repo->unDelete($archived_payment_address);
            $this->comment(json_encode($payment_address->serializeForAPI(), 192));
            if (!$payment_address) { throw new Exception("Failed to resurrect address", 1); }

            // make sure the payment address has a default account
            $default_account = AccountHandler::getAccount($payment_address);
            if (!$default_account) {
                $this->comment("Creating default account for  {$payment_address['address']} ({$payment_address['uuid']})");
                AccountHandler::createDefaultAccount($payment_address);
            }

            // reconcile balances
            $balance_differences = $blockchain_balance_reconciler->buildDifferences($payment_address);
            $blockchain_balance_reconciler->reconcileDifferences($balance_differences, $payment_address, $api_call);

            // reconcile TXOs
            $txo_differences = $blockchain_txo_reconciler->buildDifferences($payment_address);
            $blockchain_txo_reconciler->reconcileDifferences($txo_differences, $payment_address, $api_call);

            // delete the archived address
            $payment_address_archive_repo->delete($archived_payment_address);
        });

        $this->info('done');
    }


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
