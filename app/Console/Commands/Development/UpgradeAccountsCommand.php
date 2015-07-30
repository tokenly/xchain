<?php

namespace App\Console\Commands\Development;

use App\Commands\UpgradeAccounts;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class UpgradeAccountsCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:upgrade-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensures a default account for every payment address';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            // ['time', InputArgument::OPTIONAL, 'Time in seconds to prune', 36000],
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
            // ['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $this->comment('upgrading all accounts');

        $xcpd_client      = app('Tokenly\XCPDClient\Client');
        $bitcoin_payer    = app('Tokenly\BitcoinPayer\BitcoinPayer');
        $asset_info_cache = app('Tokenly\CounterpartyAssetInfoCache\Cache');
        $ledger           = app('App\Repositories\LedgerEntryRepository');

        $api_call = app('App\Repositories\APICallRepository')->create([
            'user_id' => $this->getConsoleUser()['id'],
            'details' => [
                'command' => 'xchain:upgrade-accounts',
                'args'    => [],
            ],
        ]);


        foreach (app('App\Repositories\PaymentAddressRepository')->findAll() as $payment_address) {
            $default_account = AccountHandler::getAccount($payment_address);
            if ($default_account) {
                $this->info("default account already exists for {$payment_address['address']} ({$payment_address['uuid']})");
                continue;
            }

            // add the new default account
            $this->info("creating new default account for {$payment_address['address']} ({$payment_address['uuid']})");
            AccountHandler::createDefaultAccount($payment_address);
            $default_account = AccountHandler::getAccount($payment_address);

            // get XCP balances
            $balances = $xcpd_client->get_balances(['filters' => ['field' => 'address', 'op' => '==', 'value' => $payment_address['address']]]);

            // and get BTC balance too
            $btc_float_balance = $bitcoin_payer->getBalance($payment_address['address']);
            $balances = array_merge([['asset' => 'BTC', 'quantity' => $btc_float_balance]], $balances);


            foreach($balances as $balance) {
                $asset_name = $balance['asset'];

                if ($asset_name == 'BTC') {
                    // BTC quantity is a float
                    $quantity_float = floatval($balance['quantity']);
                } else {
                    // determine quantity based on asset info
                    $is_divisible = $asset_info_cache->isDivisible($asset_name);
                    if ($is_divisible) {
                        $quantity_float = CurrencyUtil::satoshisToValue($balance['quantity']);
                    } else {
                        // non-divisible assets don't use satoshis
                        $quantity_float = floatval($balance['quantity']);
                    }
                }

                // $asset_name and $quantity_float
                $ledger->addCredit($quantity_float, $asset_name, $default_account, LedgerEntry::CONFIRMED, $txid=null, $api_call);
            }


        }

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
