<?php

namespace App\Console\Commands\Accounts;

use App\Models\APICall;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Util\ArrayToTextTable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LinusU\Bitcoin\AddressValidator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ForceSyncAddressAccountsCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:force-sync-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Forces account balances to be synced with the bitcoin and counterparty daemons';



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

            $ledger               = app('App\Repositories\LedgerEntryRepository');
            $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
            $account_repository   = app('App\Repositories\AccountRepository');
            $user_repository      = app('App\Repositories\UserRepository');
            $xcpd_client          = app('Tokenly\XCPDClient\Client');
            $bitcoin_payer        = app('Tokenly\BitcoinPayer\BitcoinPayer');
            $asset_info_cache     = app('Tokenly\CounterpartyAssetInfoCache\Cache');

            $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }

            Log::debug("reconciling accounts for {$payment_address['address']} ({$payment_address['uuid']})");

            // get XCP balances
            $balances = $xcpd_client->get_balances(['filters' => ['field' => 'address', 'op' => '==', 'value' => $payment_address['address']]]);

            // and get BTC balance too
            $btc_float_balance = $bitcoin_payer->getBalance($payment_address['address']);
            $balances = array_merge([['asset' => 'BTC', 'quantity' => $btc_float_balance]], $balances);


            // get confirmed xchain balances
            $raw_xchain_balances_by_type = $ledger->combinedAccountBalancesByAsset($payment_address, null);
            $combined_xchain_balances = [];
            foreach($raw_xchain_balances_by_type as $type_string => $xchain_balances) {
                foreach($xchain_balances as $asset => $quantity) {
                    if ($type_string == 'sending' AND $asset == 'BTC') { continue; }
                    if ($type_string == 'unconfirmed' AND $asset != 'BTC') { continue; }
                    if (!isset($combined_xchain_balances[$asset])) { $combined_xchain_balances[$asset] = 0.0; }
                    $combined_xchain_balances[$asset] += $quantity;
                }
            }

            // get daemon balances
            $daemon_balances = [];
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

                $daemon_balances[$asset_name] = $quantity_float;
            }


            // compare
            $differences = $this->buildDifferences($combined_xchain_balances, $daemon_balances);
            if ($differences['any']) {
                $this->comment("Differences found for {$payment_address['address']} ({$payment_address['uuid']})");
                $this->line(json_encode($differences, 192));
                $this->line('');

                $api_call = app('App\Repositories\APICallRepository')->create([
                    'user_id' => $this->getConsoleUser()['id'],
                    'details' => [
                        'command' => 'xchain:force-sync-accounts',
                        'args'    => ['payment_address' => $payment_address['uuid'], ],
                    ],
                ]);


                // start by clearing all unconfirmed balances
                $this->clearUnconfirmedBalances($payment_address, $api_call);

                // clear sent balances
                $this->clearSendingBalances($payment_address, $api_call);

                // sync daemon balances
                $this->syncConfirmedBalances($payment_address, $differences['differences'], $api_call);

            } else {
                Log::debug("no differences for {$payment_address['address']} ({$payment_address['uuid']})");
                // only showing one address
                $this->comment("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");
            }


        });

        $this->info('done');
    }

    protected function buildDifferences($xchain_map, $daemon_map) {
        // $items_to_add = [];
        // $items_to_delete = [];
        $differences = [];
        $any_differences = false;

        foreach($daemon_map as $daemon_map_key => $dum) {
            if (!isset($xchain_map[$daemon_map_key]) AND $daemon_map[$daemon_map_key] != 0) {
                // $items_to_add[] = [$daemon_map_key => $daemon_map[$daemon_map_key]];
                $differences[$daemon_map_key] = ['xchain' => '[NULL]', 'daemon' => $daemon_map[$daemon_map_key]];
                $any_differences = true;
            }
        }

        foreach($xchain_map as $xchain_map_key => $dum) {
            if (!isset($daemon_map[$xchain_map_key])) {
                // $items_to_delete[] = $xchain_map_key;
                $differences[$xchain_map_key] = ['xchain' => $xchain_map[$xchain_map_key], 'daemon' => '[NULL]'];
                $any_differences = true;
            } else {
                if (CurrencyUtil::valueToFormattedString($daemon_map[$xchain_map_key]) != CurrencyUtil::valueToFormattedString($xchain_map[$xchain_map_key])) {
                    $differences[$xchain_map_key] = ['xchain' => $xchain_map[$xchain_map_key], 'daemon' => $daemon_map[$xchain_map_key]];
                    $any_differences = true;
                }
            }
        }
        
        // return ['any' => $any_differences, 'add' => $items_to_add, 'updates' => $differences, 'delete' => $items_to_delete];
        return ['any' => $any_differences, 'differences' => $differences,];
    }

    protected function clearUnconfirmedBalances($payment_address, APICall $api_call) {
        $ledger               = app('App\Repositories\LedgerEntryRepository');
        $account_repository   = app('App\Repositories\AccountRepository');

        $all_accounts = $account_repository->findByAddress($payment_address);
        foreach($all_accounts as $account) {
            $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::UNCONFIRMED);
            foreach($balances as $asset => $quantity) {
                if ($quantity == 0) { continue; }

                // debit the unconfirmed balance
                $msg = "Clearing unconfirmed $quantity $asset from account {$account['name']}";
                $this->info($msg);

                // $ledger->deleteByTXID();

                $ledger->addDebit($quantity, $asset, $account, LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, null, $api_call);
            }
        }
    }

    protected function clearSendingBalances($payment_address, APICall $api_call) {
        $ledger               = app('App\Repositories\LedgerEntryRepository');
        $account_repository   = app('App\Repositories\AccountRepository');

        $all_accounts = $account_repository->findByAddress($payment_address);
        foreach($all_accounts as $account) {
            $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::SENDING);
            foreach($balances as $asset => $quantity) {
                if ($quantity == 0) { continue; }

                // debit the sending balance
                $msg = "Clearing sending $quantity $asset from account {$account['name']}";
                $this->info($msg);
                $ledger->addDebit($quantity, $asset, $account, LedgerEntry::SENDING, LedgerEntry::DIRECTION_OTHER, null, $api_call);
            }
        }
    }

    protected function syncConfirmedBalances($payment_address, $balance_differences, APICall $api_call) {
        $ledger = app('App\Repositories\LedgerEntryRepository');

        foreach($balance_differences as $asset => $qty_by_type) {
            $xchain_quantity = $qty_by_type['xchain'];
            $daemon_quantity = $qty_by_type['daemon'];

            $default_account = AccountHandler::getAccount($payment_address);
            if ($xchain_quantity > $daemon_quantity) {
                // debit
                $quantity = $xchain_quantity - $daemon_quantity;
                $msg = "Debiting $quantity $asset from account {$default_account['name']}";
                $this->info($msg);
                $ledger->addDebit($quantity, $asset, $default_account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, null, $api_call);
            } else {
                // credit
                $quantity = $daemon_quantity - $xchain_quantity;
                $msg = "Crediting $quantity $asset to account {$default_account['name']}";
                $this->info($msg);
                $ledger->addCredit($quantity, $asset, $default_account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, null, $api_call);
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
