<?php

namespace App\Console\Commands\Address;

use App\Models\PaymentAddressArchive;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class FindAddressCommand extends Command {

    use DispatchesJobs;

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
            ['uuid', 'u', InputOption::VALUE_NONE, 'Show UUID only'],
            ['archived', null, InputOption::VALUE_NONE, 'Show archived only'],
            ['live', 'l', InputOption::VALUE_NONE, 'Show live only'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $uuid_only = $this->input->getOption('uuid');
        DB::transaction(function() {

            $payment_address_repo = app('App\Repositories\PaymentAddressRepository');

            $address = $this->input->getArgument('address');
            $uuid_only = $this->input->getOption('uuid');
            $archived_only = $this->input->getOption('archived');
            $live_only = $this->input->getOption('live');

            $found = false;

            if (!$archived_only) {
                $payment_addresses = $payment_address_repo->findByAddress($address)->get();
                if ($payment_addresses->count()) {
                    if ($uuid_only AND $payment_addresses->count() > 1) {
                        throw new Exception("Found multiple addresses", 1);
                    }
                    $found = true;
        
                    if (!$uuid_only) {
                        $this->info("found {$payment_addresses->count()} address".($payment_addresses->count() > 1 ? "es" : ""));
                    }

                    $payment_addresses->each(function($payment_address) use ($uuid_only) {
                        if ($uuid_only) {
                            $this->line($payment_address['uuid']);
                        } else {
                            $this->comment("Address {$payment_address['address']} (UUID: {$payment_address['uuid']} ID: {$payment_address['id']})");
                        }
                    });
                }

            }

            // try archived if not found
            if (!$found AND !$live_only) {
                $payment_addresses = PaymentAddressArchive::where('address', $address)->get();

                if ($payment_addresses->count()) {
                    if ($uuid_only AND $payment_addresses->count() > 1) {
                        throw new Exception("Found multiple ARCHIVED addresses", 1);
                    }
                    $found = true;
                
                    if (!$uuid_only) {
                        $this->info("found {$payment_addresses->count()} ARCHIVED address".($payment_addresses->count() > 1 ? "es" : ""));
                    }

                    $payment_addresses->each(function($payment_address) use ($uuid_only) {
                        if ($uuid_only) {
                            $this->line($payment_address['uuid']);
                        } else {
                            $this->comment("ARCHIVED Address {$payment_address['address']} (UUID: {$payment_address['uuid']} ID: {$payment_address['id']})");
                        }
                    });
                }
            }

            if (!$found) {
                throw new Exception("Payment address not found for address $address", 1);
            }
        });

        if (!$uuid_only) {
            $this->info('done');
        }
    }



}
