<?php

namespace App\Console\Commands\Accounts;

use App\Models\APICall;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class BalanceLedgerCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:balance-ledger';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Forces ledger entries to be reconciled to 0';



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
            ['process',     'p', InputOption::VALUE_NONE, 'Update the ledgers to be zero'],
            ['unconfirmed', 'u', InputOption::VALUE_NONE, 'Balanced unconfirmed ledger'],
            ['sending',     's', InputOption::VALUE_NONE, 'Balanced sending ledger'],
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

            $ledger               = app('App\Repositories\LedgerEntryRepository');
            $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
            $account_repository   = app('App\Repositories\AccountRepository');
            $user_repository      = app('App\Repositories\UserRepository');
            $asset_info_cache     = app('Tokenly\CounterpartyAssetInfoCache\Cache');

            $do_unconfirmed = $this->input->getOption('unconfirmed');
            $do_sending = $this->input->getOption('sending');
            $do_process = $this->input->getOption('process');

            $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }

            Log::debug("balancing ledger for {$payment_address['address']} ({$payment_address['uuid']})");


            // get xchain balances
            $raw_xchain_balances_by_type = $ledger->combinedAccountBalancesByAsset($payment_address, null);
            $non_zero_balances = [];
            foreach($raw_xchain_balances_by_type as $type_string => $xchain_balances) {
                if (!isset($non_zero_balances[$type_string])) { $non_zero_balances[$type_string] = []; }
                foreach($xchain_balances as $asset => $quantity) {
                    if ($type_string == 'sending' AND $do_sending) {
                        if ($quantity != 0) {
                            $non_zero_balances[$type_string][$asset] = $quantity;
                        }
                    }
                    if ($type_string == 'unconfirmed' AND $do_unconfirmed) {
                        if ($quantity != 0) {
                            $non_zero_balances[$type_string][$asset] = $quantity;
                        }
                    }
                }
            }


            if (!$non_zero_balances) {
                $this->comment("No mismatched balances found");
                return;
            }


            $this->line("Found non-zero balances");
            $this->line(json_encode($non_zero_balances, 192));
            $this->line("");

            // compare
            if ($do_process) {
                $this->comment("Zeroing balances");
                $this->line('');

                $api_call = app('App\Repositories\APICallRepository')->create([
                    'user_id' => $this->getConsoleUser()['id'],
                    'details' => [
                        'command' => 'xchain:balance-ledger',
                        'args'    => ['payment_address' => $payment_address['uuid'], ],
                    ],
                ]);


                // balance ledgers
                if ($do_unconfirmed) {
                    $this->clearBalances('unconfirmed', $non_zero_balances['unconfirmed'], $payment_address, $api_call);
                }

                if ($do_sending) {
                    $this->clearBalances('sending', $non_zero_balances['sending'], $payment_address, $api_call);
                }


            } else {
                Log::debug("no differences for {$payment_address['address']} ({$payment_address['uuid']})");
                // only showing one address
                $this->comment("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");
            }


        });

        $this->info('done');
    }


    protected function clearBalances($balance_type, $balances_by_asset, $payment_address, APICall $api_call) {
        $ledger             = app('App\Repositories\LedgerEntryRepository');

        // get the default account
        $account = AccountHandler::getAccount($payment_address, 'default');

        foreach($balances_by_asset as $asset => $quantity) {
            if ($quantity == 0) { continue; }

            // debit the {$balance_type} balance
            $msg = "Balancing {$balance_type} ledger with $quantity $asset";
            $this->info($msg);
            $ledger_entry_type = ($balance_type == 'unconfirmed' ? LedgerEntry::UNCONFIRMED : LedgerEntry::SENDING);
            if ($quantity < 0) {
                $ledger->addCredit(0-$quantity, $asset, $account, $ledger_entry_type, null, $api_call);
            } else {
                $ledger->addDebit($quantity, $asset, $account, $ledger_entry_type, null, $api_call);
            }
        }
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
