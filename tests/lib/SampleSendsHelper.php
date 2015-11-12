<?php

use App\Repositories\SendRepository;
use Rhumsaa\Uuid\Uuid;
use Tokenly\CurrencyLib\CurrencyUtil;

/**
*  SampleSendsHelper
*/
class SampleSendsHelper
{

    public function __construct(SendRepository $send_repository, UserHelper $user_helper, PaymentAddressHelper $payment_address_helper) {
        $this->payment_address_helper = $payment_address_helper;
        $this->user_helper            = $user_helper;
        $this->send_repository        = $send_repository;
    }

    public function createSampleSend($override_vars=[]) {
        $user = $this->user_helper->createSampleUser();
        $payment_address = $this->payment_address_helper->createSamplePaymentAddress($user);
        return $this->createSampleSendWithPaymentAddress($payment_address, $override_vars);
    }

    public function createSampleSendWithPaymentAddress($payment_address, $override_vars=[]) {
        $attributes = $this->sampleVars();
        $attributes['payment_address_id'] = $payment_address['id'];
        $attributes['user_id'] = $payment_address['user_id'];
        $attributes = array_merge($attributes, $override_vars);
        return $this->send_repository->create($attributes);
    }

    public function sampleVars($override_vars=[]) {
        // apply sample post vars
        $override_vars = $this->samplePostVars($override_vars);

        $vars = array_merge([
            'txid'      => 'SAMPLETXID000000000000000000000000000000000000000000000000000001',
        ], $override_vars);

        if (isset($vars['quantity'])) {
            $vars['quantity_sat'] = CurrencyUtil::valueToSatoshis($vars['quantity']);
            unset($vars['quantity']);
        }

        if (isset($vars['requestId'])) {
            $vars['request_id'] = CurrencyUtil::valueToSatoshis($vars['requestId']);
            unset($vars['requestId']);
        }

        return $vars;
    }

    public function samplePostVars($override_vars=[]) {
        return array_merge([
            'requestId'   => Uuid::uuid4()->toString(),
            'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'quantity'    => 100,
            'asset'       => 'TOKENLY',
        ], $override_vars);
    }


    public function sampleMultisendPostVars($override_vars=[]) {
        return array_merge([
            'requestId'   => Uuid::uuid4()->toString(),
            'destinations' => [
                ['address' => '1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', 'amount' => '0.001', ],
                ['address' => '1ATEST222XXXXXXXXXXXXXXXXXXXYzLVeV', 'amount' => '0.002', ],
                ['address' => '1ATEST333XXXXXXXXXXXXXXXXXXXatH8WE', 'amount' => '0.003', ],
            ],
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return $this->sampleVars($override_vars);
    }

}

