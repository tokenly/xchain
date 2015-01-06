<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use App\Providers\EventLog\Facade\EventLog;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\BitcoinAddressLib\BitcoinKeyUtils;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartySender\CounterpartySender;

class PaymentAddressSender {

    const DEFAULT_FEE                = 0.0001;
    const DEFAULT_MULTISIG_DUST_SIZE = 0.000025;

    public function __construct(CounterpartySender $xcpd_sender, BitcoinPayer $bitcoin_payer, BitcoinAddressGenerator $address_generator) {
        $this->xcpd_sender       = $xcpd_sender;
        $this->bitcoin_payer     = $bitcoin_payer;
        $this->address_generator = $address_generator;
    }

    public function sweepBTC(PaymentAddress $payment_address, $destination, $float_fee=null) {
        return $this->send($payment_address, $destination, null, 'BTC', $float_fee, null, true);
    }

    public function send(PaymentAddress $payment_address, $destination, $quantity, $asset, $float_fee=null, $multisig_dust_size=null, $is_sweep=false) {
        if ($float_fee === null) { $float_fee = self::DEFAULT_FEE; }
        if ($multisig_dust_size === null) { $multisig_dust_size = self::DEFAULT_MULTISIG_DUST_SIZE; }
        $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
        $public_key = BitcoinKeyUtils::publicKeyFromPrivateKey($private_key);
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);

        if ($is_sweep) {
            if (strtoupper($asset) != 'BTC') { throw new Exception("Sweep is only allowed for BTC.", 1); }
            
            $txid = $this->bitcoin_payer->sweepBTC($payment_address['address'], $destination, $wif_private_key, $float_fee);
        } else {
            if (strtoupper($asset) == 'BTC') {
                $txid = $this->bitcoin_payer->sendBTC($payment_address['address'], $destination, $quantity, $wif_private_key, $float_fee);
            } else {
                $other_xcp_vars = [
                    'fee_per_kb'               => $float_fee,
                    'multisig_dust_size'       => $multisig_dust_size,
                    'allow_unconfirmed_inputs' => true,
                ];
                $txid = $this->xcpd_sender->send($public_key, $wif_private_key, $payment_address['address'], $destination, $quantity, $asset, $other_xcp_vars);
            }
        }

        return $txid;
    }


}
