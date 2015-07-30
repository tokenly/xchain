<?php

namespace App\Console\Commands\Development;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Util\ArrayToTextTable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use LinusU\Bitcoin\AddressValidator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Filter\IndexRequestFilter;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ShowAccountsCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:show-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Shows all accounts for address';



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
            ['name', null, InputOption::VALUE_OPTIONAL, 'Filter by account name'],
            ['ledger', 'l', InputOption::VALUE_NONE, 'Show all ledger entries'],
            ['inactive', 'i', InputOption::VALUE_NONE, 'Include inactive accounts'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $this->info('Sweeping account');

        $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
        $account_repository =   app('App\Repositories\AccountRepository');
        $user_repository =      app('App\Repositories\UserRepository');

        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
        $name = $this->input->getOption('name');
        $show_ledger = $this->input->getOption('ledger');
        $show_inactive = $this->input->getOption('inactive');

        $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
        if (!$payment_address) { throw new Exception("Payment address not found", 1); }


        if (strlen($name)) {
            $account = AccountHandler::getAccount($payment_address, $name);
            if (!$account) {
                $this->error("Account not found for $name");
                return;
            }
            $accounts = [$account];
        } else {
            // all accounts, maybe including inactive
            $accounts = $account_repository->findByAddress($payment_address, $show_inactive ? null : 1);
        }

        foreach($accounts as $account) {
            $this->line($this->showAccount($account, $show_ledger));
        }

        $this->info('done');
    }


    protected function showAccount(Account $account, $show_ledger=false) {
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $all_account_balances = $ledger->accountBalancesByAsset($account, null);

        $sep = str_repeat('-', 60)."\n";
        $out = '';
        $out .= "{$sep}{$account['name']} ({$account['id']}, {$account['uuid']})\n{$sep}";

        if ($show_ledger) {
            $out .= "\n";
            $rows = [];
            $all_entries = $ledger->findByAccount($account);
            foreach($all_entries as $entry) {
                $row = [];

                $row['date'] = $entry['created_at']->setTimezone('America/Chicago')->format('Y-m-d H:i:s T');
                $row['type'] = LedgerEntry::typeIntegerToString($entry['type']);
                $row['amount'] = CurrencyUtil::satoshisToValue($entry['amount']);
                $row['asset'] = $entry['asset'];
                $row['txid'] = $entry['txid'];

                $rows[] = $row;
            }


            $renderer = new ArrayToTextTable($rows);
            $renderer->showHeaders(true);
            $out .= $renderer->render(true)."\n";
        }

        // $out .= "BALANCES\n";
        $out .= "\n";
        foreach (LedgerEntry::allTypeStrings() as $type_string) {
            $out .= "$type_string:\n";
            if (isset($all_account_balances[$type_string]) AND $all_account_balances[$type_string]) {
                foreach ($all_account_balances[$type_string] as $asset => $balance) {
                    $out .= "  $asset: ".CurrencyUtil::valueToFormattedString($balance)."\n";
                }
            } else {
                $out .= "  [empty]\n";
            }
        }

        $out .= "\n{$sep}\n";

        return $out;
    }

}
