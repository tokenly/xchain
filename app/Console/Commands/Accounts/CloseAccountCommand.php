<?php

namespace App\Console\Commands\Accounts;

use App\Providers\Accounts\Facade\AccountHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CloseAccountCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:close-account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Closes an account for a payment address';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['payment-address-uuid', InputArgument::REQUIRED, 'Payment Address UUID'],
            ['account-name', InputArgument::REQUIRED, 'Account name'],
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

            $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
            $account_repository   = app('App\Repositories\AccountRepository');

            $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }

            $account_name = $this->input->getArgument('account-name');

            $api_call = app('App\Repositories\APICallRepository')->create([
                'user_id' => $this->getConsoleUser()['id'],
                'details' => [
                    'command' => 'xchain:close-account',
                    'args'    => ['payment_address' => $payment_address['uuid'], ],
                ],
            ]);

            $from_account = $account_repository->findByName($account_name, $payment_address['id']);
            if (!$from_account) {
                $this->error("Account $from_account not found.");
                return;
            }

            $this->comment('closing account '.$account_name);
            AccountHandler::close($payment_address, $account_name, 'default', $api_call);
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
