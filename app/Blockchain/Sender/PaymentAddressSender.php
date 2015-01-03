<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use App\Providers\EventLog\Facade\EventLog;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\BitcoinAddressLib\BitcoinKeyUtils;
use Tokenly\CounterpartySender\CounterpartySender;

class PaymentAddressSender {

    public function __construct(CounterpartySender $xcpd_sender, BitcoinAddressGenerator $address_generator) {
        $this->xcpd_sender       = $xcpd_sender;
        $this->address_generator = $address_generator;
    }

    public function send(PaymentAddress $payment_address, $destination, $quantity, $asset) {
        $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
        $public_key = BitcoinKeyUtils::publicKeyFromPrivateKey($private_key);
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);

        $txid = $this->xcpd_sender->send($public_key, $wif_private_key, $payment_address['address'], $destination, $quantity, $asset);
        return $txid;
    }


}
