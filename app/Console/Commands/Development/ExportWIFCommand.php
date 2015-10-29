<?php

namespace App\Console\Commands\Development;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Exception;

class ExportWIFCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-xchain:export-wif';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export WIF (for development)';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();


        $this
            ->addArgument('address-uuid', InputArgument::REQUIRED, 'Address UUID')
            ->setHelp(<<<EOF
Export WIF key
EOF
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $payment_address_repo = $this->laravel->make('App\Repositories\PaymentAddressRepository');
        $payment_address = $payment_address_repo->findByUuid($this->input->getArgument('address-uuid'));
        if (!$payment_address) { throw new Exception("Payment address not found", 1); }

        $address_generator = $this->laravel->make('Tokenly\BitcoinAddressLib\BitcoinAddressGenerator');
        $wif = $address_generator->WIFPrivateKey($payment_address['private_key_token']);

        $this->line("Address: ".$payment_address['address']);
        $this->line("WIF: ".$wif);

        $this->comment("done");

    }


}
