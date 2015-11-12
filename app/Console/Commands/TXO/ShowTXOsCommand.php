<?php

namespace App\Console\Commands\TXO;

use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Symfony\Component\Console\Helper\Table;

class ShowTXOsCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchaintxo:show-txos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Shows UTXOs';



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
            ['spent', 's', InputOption::VALUE_NONE, 'Inclucde spent TXOs'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $txo_repository       = app('App\Repositories\TXORepository');
        $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
        $bitcoin_payer        = app('Tokenly\BitcoinPayer\BitcoinPayer');
        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
        $show_spent           = $this->input->getOption('spent');

        if ($payment_address_uuid) {
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }
            $payment_addresses = [$payment_address];
        } else {
            $payment_addresses = $payment_address_repo->findAll();
        }

        $xchain_utxos_map = [];
        foreach($payment_addresses as $payment_address) {
            // get xchain utxos
            $db_txos = $txo_repository->findByPaymentAddress($payment_address, null, $show_spent ? null : true); // all or unspent only
            foreach($db_txos as $db_txo) {
                $xchain_utxos_map[$db_txo['txid'].':'.$db_txo['n']] = $db_txo;
            }
        }

        // build a table
        $bool = function($val) { return $val ? '<info>true</info>' : '<comment>false</comment>'; };
        $headers = ['address', 'txid', 'n', 'amount', 'type', 'spent', 'green', 'created'];
        $rows = [];
        foreach($xchain_utxos_map as $identifier => $txo) {
            $address = $payment_address_repo->findById($txo['payment_address_id']);
            $pieces = explode('.', CurrencyUtil::satoshisToFormattedString($txo['amount']));
            if (count($pieces) == 2) {
                $amount = $pieces[0].".".str_pad($pieces[1], 8, '0', STR_PAD_RIGHT);
            } else {
                $amount = $amount.".00000000";
            }

            $created = $txo['created_at']->setTimezone('America/Chicago')->format("Y-m-d h:i:s A");
            $type = TXO::typeIntegerToString($txo['type']);
            $rows[] = [$address['address'], $txo['txid'], $txo['n'], $amount, $type, $bool($txo['spent']), $bool($txo['green']), $created];
        }
        $this->table($headers, $rows);

        $this->info('done');
    }

}
