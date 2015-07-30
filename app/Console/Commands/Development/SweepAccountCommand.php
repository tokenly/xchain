<?php

namespace App\Console\Commands\Development;

use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use LinusU\Bitcoin\AddressValidator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class SweepAccountCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:sweep-address';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweeps all assets from an address';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['payment-address-id', InputArgument::REQUIRED, 'Payment Address UUID'],
            ['destination-address', InputArgument::REQUIRED, 'Destination Bitcoin Address'],
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
            ['fee', 'f', InputOption::VALUE_OPTIONAL, 'transaction fee', 0.0001],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $this->comment('Sweeping account');

        $address_sender = app('App\Blockchain\Sender\PaymentAddressSender');

        $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
        $payment_address = $payment_address_repo->findByUuid($this->input->getArgument('payment-address-id'));
        if (!$payment_address) { throw new Exception("Payment address not found", 1); }

        $destination = $this->input->getArgument('destination-address');
        if (!AddressValidator::isValid($destination)) { throw new Exception("The destination address was invalid", 1); }

        $float_fee = $this->input->getOption('fee');

        $api_call = app('App\Repositories\APICallRepository')->create([
            'user_id' => $this->getConsoleUser()['id'],
            'details' => [
                'command' => 'xchain:sweep-address',
                'args'    => ['payment_address' => $payment_address['uuid'], 'destination' => $destination, 'fee' => $float_fee, ],
            ],
        ]);

        // get lock
        $lock_acquired = AccountHandler::acquirePaymentAddressLock($payment_address);

        // do the send
        list($txid, $float_balance_sent) = $address_sender->sweepAllAssets($payment_address, $destination, $float_fee);

        // clear all balances from all accounts
        AccountHandler::zeroAllBalances($payment_address, $api_call);

        // release the account lock
        if ($lock_acquired) { AccountHandler::releasePaymentAddressLock($payment_address); }

        $this->comment('done');
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
