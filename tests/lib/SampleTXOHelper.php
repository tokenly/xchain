<?php

use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\TXORepository;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;

/**
*  SampleTXOHelper
*/
class SampleTXOHelper
{

    static $SAMPLE_TXID = 0;

    public function __construct(TXORepository $txo_repository) {
        $this->txo_repository = $txo_repository;
    }

    public function createSampleTXO($payment_address=null, $overrides=[]) {
        if ($payment_address === null) {
            $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        }

        $account = AccountHandler::getAccount($payment_address, 'default');

        // build a real script
        $script = ScriptFactory::scriptPubKey()->payToAddress(AddressFactory::fromString($payment_address['address']));

        $attributes = array_merge([
            'txid'   => $this->nextTXID(),
            'n'      => 0,
            'script' => $script->getBuffer()->getHex(),
            'amount' => 54321,
            'type'   => TXO::CONFIRMED,
            'spent'  => false,
            'green'  => false,
        ], $overrides);
        $txo_model = $this->txo_repository->create($payment_address, $account, $attributes);
        return $txo_model;
    }

    public function nextTXID() {
        return str_repeat('1', 60).sprintf('%04d', (++self::$SAMPLE_TXID));
    }
}