<?php

namespace App\Console\Commands\Experiment;

use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcSerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Signature\CompactSignatureSerializerInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\MessageSigner\MessageSigner;
use BitWasp\Bitcoin\Serializer\MessageSigner\SignedMessageSerializer;
use BitWasp\Buffertools\Buffer;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;



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

        // generate the testing private key
        $platform_key = 'testingfoo123';
        $address_token = 'ACwKzfjlvWQrLQhQCbalycfd2mThk7jEbfw85B1y';
        $address_generator = new BitcoinAddressGenerator($platform_key);
        $priv_key = $address_generator->privateKey($address_token);

        $pub_key = $priv_key->getPublicKey();
        $address = AddressFactory::fromKey($priv_key)->getAddress();
        $message = 'hi';
        $signer = new MessageSigner();
        $signed = $signer->sign($message, $priv_key);
        $signed_string = base64_encode($signed->getCompactSignature()->getBinary());

        // signed message
        echo "   priv: ".$priv_key->getHex()."\n";
        echo "    pub: ".$pub_key->getHex()."\n";
        echo "address: ".$address."\n";
        echo " signed: ".$signed_string."\n";



        // verify signature
        $address_obj = AddressFactory::fromString($address);
        $signer = new MessageSigner();
        $cs = EcSerializer::getSerializer(CompactSignatureSerializerInterface::class);
        $serializer = new SignedMessageSerializer($cs);

        $built_signature = 
            "-----BEGIN BITCOIN SIGNED MESSAGE-----"."\n"
            .$message."\n"
            ."-----BEGIN SIGNATURE-----"."\n"
            .$signed_string."\n"
            ."-----END BITCOIN SIGNED MESSAGE-----";

        $signedMessage = $serializer->parse($built_signature);

        if ($signer->verify($signedMessage, $address_obj)) {
            echo "Signature verified!\n";
        } else {
            echo "Failed to verify signature!\n";
        }


        $this->comment("End experiment");
    }

}
