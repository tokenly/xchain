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

class ReconcileTXOsCommand extends Command {

    use DispatchesCommands;

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

        $txo_repository       = app('App\Repositories\TXORepository');
        $payment_address_repo = app('App\Repositories\PaymentAddressRepository');
        $bitcoin_payer        = app('Tokenly\BitcoinPayer\BitcoinPayer');
        $payment_address_uuid = $this->input->getArgument('payment-address-uuid');
        $should_reconcile     = $this->input->getOption('reconcile');

        if ($payment_address_uuid) {
            $payment_address = $payment_address_repo->findByUuid($payment_address_uuid);
            if (!$payment_address) { throw new Exception("Payment address not found", 1); }
            $payment_addresses = [$payment_address];
        } else {
            $payment_addresses = $payment_address_repo->findAll();
        }

        foreach($payment_addresses as $payment_address) {
            Log::debug("reconciling TXOs for {$payment_address['address']} ({$payment_address['uuid']})");

            // get xchain utxos
            $xchain_utxos_map = [];
            $db_txos = $txo_repository->findByPaymentAddress($payment_address, null, true); // unspent only
            foreach($db_txos as $db_txo) {
                if ($db_txo['type'] != TXO::CONFIRMED) { continue; }
                $filtered_utxo = ['txid' => $db_txo['txid'], 'n' => $db_txo['n'], 'script' => $db_txo['script'], 'amount' => $db_txo['amount'],];
                $xchain_utxos_map[$filtered_utxo['txid'].':'.$filtered_utxo['n']] = $filtered_utxo;
            }

            // get daemon utxos
            $daemon_utxos_map = [];
            $all_utxos = $bitcoin_payer->getAllUTXOs($payment_address['address']);
            if ($all_utxos) {
                foreach($all_utxos as $utxo) {
                    if (!$utxo['confirmed']) { continue; }
                    $filtered_utxo = ['txid' => $utxo['txid'], 'n' => $utxo['vout'], 'script' => $utxo['script'], 'amount' => CurrencyUtil::valueToSatoshis($utxo['amount']),];
                    $daemon_utxos_map[$utxo['txid'].':'.$utxo['vout']] = $filtered_utxo;
                }
            }


            // compare
            $differences = $this->buildDifferences($xchain_utxos_map, $daemon_utxos_map);
            if ($differences['any']) {
                $this->comment("Differences found for {$payment_address['address']} ({$payment_address['uuid']})");
                $this->line(json_encode($differences, 192));
                $this->line('');

                if ($should_reconcile) {
                    $account = AccountHandler::getAccount($payment_address);
                    foreach($differences['differences'] as $difference) {
                        // delete the xchain txo if it is different in any way
                        if (isset($difference['xchain']) AND $difference['xchain']) {
                            $utxo = $difference['xchain'];
                            $this->line('Removing xchain UTXO: '.json_encode($utxo, 192));

                            // delete
                            $txo_model = $txo_repository->findByTXIDAndOffset($utxo['txid'], $utxo['n']);
                            if ($txo_model['payment_address_id'] != $payment_address['id']) {
                                throw new Exception("Mismatched payment address id.  Expected {$payment_address['id']}.  Found {$txo_model['payment_address_id']}", 1);
                            }
                        }

                        if (isset($difference['daemon'])) {
                            $utxo = $difference['daemon'];
                            $this->line('Adding daemon UTXO: '.json_encode($utxo, 192));

                            // add the daemon's utxo
                            $txo_repository->create($payment_address, $account, [
                                'txid'   => $utxo['txid'],
                                'n'      => $utxo['n'],
                                'script' => $utxo['script'],
                                'amount' => $utxo['amount'],
                            ]);
                        }
                    }
                }
            } else {
                $this->comment("No differences found for {$payment_address['address']} ({$payment_address['uuid']})");
            }

        }

        $this->info('done');
    }

    protected function buildDifferences($xchain_utxos_map, $daemon_utxos_map) {
        $differences = [];
        $any_differences = false;

        foreach($daemon_utxos_map as $daemon_utxo_key => $dum) {
            if (!isset($xchain_utxos_map[$daemon_utxo_key])) {
                $differences[$daemon_utxo_key] = ['xchain' => null, 'daemon' => $daemon_utxos_map[$daemon_utxo_key]];
                $any_differences = true;
            }
        }

        foreach($xchain_utxos_map as $xchain_utxo_key => $dum) {
            if (!isset($daemon_utxos_map[$xchain_utxo_key])) {
                $differences[$xchain_utxo_key] = ['xchain' => $xchain_utxos_map[$xchain_utxo_key], 'daemon' => null];
                $any_differences = true;
            } else {
                if ($this->utxosAreDifferent($daemon_utxos_map[$xchain_utxo_key], $xchain_utxos_map[$xchain_utxo_key])) {
                    $differences[$xchain_utxo_key] = ['xchain' => $xchain_utxos_map[$xchain_utxo_key], 'daemon' => $daemon_utxos_map[$xchain_utxo_key]];
                    $any_differences = true;
                }
            }
        }

        
        // return ['any' => $any_differences, 'add' => $items_to_add, 'updates' => $differences, 'delete' => $items_to_delete];
        return ['any' => $any_differences, 'differences' => $differences,];
    }

    protected function utxosAreDifferent($utxo1, $utxo2) {
        return ($utxo1['amount'] != $utxo2['amount']);
    }
}
