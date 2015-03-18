<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\BitcoinAddressLib\BitcoinKeyUtils;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CurrencyLib\CurrencyUtil;

class PaymentAddressSender {

    const DEFAULT_FEE                = 0.0001;

    const DEFAULT_REGULAR_DUST_SIZE  = 0.00005430;
    const DEFAULT_MULTISIG_DUST_SIZE = 0.00007800;

    public function __construct(CounterpartySender $xcpd_sender, BitcoinPayer $bitcoin_payer, BitcoinAddressGenerator $address_generator, Cache $asset_cache) {
        $this->xcpd_sender       = $xcpd_sender;
        $this->bitcoin_payer     = $bitcoin_payer;
        $this->address_generator = $address_generator;
        $this->asset_cache       = $asset_cache;
    }

    // returns [$transaction_id, $float_balance_sent]
    public function sweepBTC(PaymentAddress $payment_address, $destination, $float_fee=null) {
        return $this->send($payment_address, $destination, null, 'BTC', $float_fee, null, null, true);
    }

    public function send(PaymentAddress $payment_address, $destination, $float_quantity, $asset, $float_fee=null, $float_regular_dust_size=null, $float_multisig_dust_size=null, $is_sweep=false) {
        if ($float_fee === null)                { $float_fee                = self::DEFAULT_FEE; }
        if ($float_regular_dust_size === null)  { $float_regular_dust_size  = self::DEFAULT_REGULAR_DUST_SIZE; }
        if ($float_multisig_dust_size === null) { $float_multisig_dust_size = self::DEFAULT_MULTISIG_DUST_SIZE; }
        $private_key = $this->address_generator->privateKey($payment_address['private_key_token']);
        $public_key = BitcoinKeyUtils::publicKeyFromPrivateKey($private_key);
        $wif_private_key = BitcoinKeyUtils::WIFFromPrivateKey($private_key);

        if ($is_sweep) {
            if (strtoupper($asset) != 'BTC') { throw new Exception("Sweep is only allowed for BTC.", 1); }
            
            $txid = $this->bitcoin_payer->sweepBTC($payment_address['address'], $destination, $wif_private_key, $float_fee);
        } else {
            if (strtoupper($asset) == 'BTC') {
                $txid = $this->bitcoin_payer->sendBTC($payment_address['address'], $destination, $float_quantity, $wif_private_key, $float_fee);
            } else {
                // if the asset is divisible, then convert to satoshis
                $is_divisible = $this->asset_cache->isDivisible($asset);
                if ($is_divisible) {
                    // divisible - convert to satoshis
                    $quantity_for_xcpd = CurrencyUtil::valueToSatoshis($float_quantity);
                } else {
                    // not divisible - do not use satoshis
                    $quantity_for_xcpd = intval($float_quantity);
                }

                $other_xcp_vars = [
                    'fee_per_kb'               => CurrencyUtil::valueToSatoshis($float_fee),
                    'regular_dust_size'        => CurrencyUtil::valueToSatoshis($float_regular_dust_size),
                    'multisig_dust_size'       => CurrencyUtil::valueToSatoshis($float_multisig_dust_size),
                    'allow_unconfirmed_inputs' => true,
                ];
                $txid = $this->xcpd_sender->send($public_key, $wif_private_key, $payment_address['address'], $destination, $quantity_for_xcpd, $asset, $other_xcp_vars);
            }
        }

        return $txid;
    }


}
