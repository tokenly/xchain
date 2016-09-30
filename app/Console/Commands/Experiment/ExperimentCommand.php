<?php

namespace App\Console\Commands\Experiment;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;



class ExperimentCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-exp:expirement';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Used for experiments';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->comment("Begin experiment");

        $priv = PrivateKeyFactory::fromHex(Hash::sha256(new Buffer('abcxxx')));
        $publicKey = $priv->getPublicKey();


        $tx_builder = TransactionFactory::build();

        $tx_builder->input('10001234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234', 0);
        // $tx_builder->payToAddress(60, \BitWasp\Bitcoin\Address\AddressFactory::fromString('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD'));
        $tx_builder->payToAddress(60, \BitWasp\Bitcoin\Address\AddressFactory::fromString('1NEwmNSC7w9nZeASngHCd43Bc5eC2FmXpn'));
        $tx = $tx_builder->get();
        
        $signer = new \BitWasp\Bitcoin\Transaction\Factory\Signer($tx, \BitWasp\Bitcoin\Bitcoin::getEcAdapter());
        $signer->sign(0, $priv, $tx->getOutput(0));
        $signed = $signer->get();



        echo "\$signed input 0: ".$signed->getInput(0)->getScript()->getHex()."\n";
        echo $signed->getHex()."\n";






        // $tx_builder->payToAddress(60, \BitWasp\Bitcoin\Address\AddressFactory::fromString('1NEwmNSC7w9nZeASngHCd43Bc5eC2FmXpn'));
        // $tx_builder->payToAddress(60, $publicKey2->getAddress());
        // $tx_builder->payToAddress(60, \BitWasp\Bitcoin\Address\AddressFactory::fromString($publicKey->getAddress()->getAddress()));

        // $addr_instance = \BitWasp\Bitcoin\Address\AddressFactory::fromString('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD');
        // echo "\$addr_instance: ".get_class($addr_instance)." ".$addr_instance->getAddress()."\n";

        // echo "\$addr_instance (pubkey): ".get_class($publicKey->getAddress())." ".$publicKey->getAddress()->getAddress()."\n";
        // echo "\$addr_instance (pubkey): ".get_class(\BitWasp\Bitcoin\Address\AddressFactory::fromString($publicKey->getAddress()->getAddress()))."\n";

        // $priv2 = PrivateKeyFactory::fromHex(Hash::sha256(new Buffer('abcd')));
        // $publicKey2 = $priv2->getPublicKey();



        $this->comment("End experiment");
    }

}
