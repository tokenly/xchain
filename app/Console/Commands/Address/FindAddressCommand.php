<?php

namespace App\Console\Commands\Address;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class FindAddressCommand extends Command {

    use DispatchesCommands;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:find-address';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find payment address by base58 address';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['address', InputArgument::REQUIRED, 'Bitcoin address'],
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

            $payment_address_repo = app('App\Repositories\PaymentAddressRepository');

            $address = $this->input->getArgument('address');
            $payment_addresses = $payment_address_repo->findByAddress($address)->get();
            if (!$payment_addresses->count()) { throw new Exception("Payment address not found for address $address", 1); }
            $this->info("found {$payment_addresses->count()} address".($payment_addresses->count() > 1 ? "es" : ""));

            $payment_addresses->each(function($payment_address) {
                $this->comment("Address {$payment_address['address']} ({$payment_address['uuid']})");
            });
        });

        $this->info('done');
    }



}
