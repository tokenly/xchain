<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class PaymentAddressRepositoryTest extends TestCase {

    protected $useDatabase = true;


    public function testFindPaymentAddressesOnly()
    {
        // insert
        $payment_address_helper = $this->app->make('\PaymentAddressHelper');
        $payment_address_repo = $this->app->make('App\Repositories\PaymentAddressRepository');
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment111111111111111111111111']));
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment222222222222222222222222']));
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment333333333333333333333333']));
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment444444444444444444444444']));

        // load from repo
        $addresses = $payment_address_repo->findAllAddresses();
        PHPUnit::assertEquals(['1payment111111111111111111111111','1payment222222222222222222222222','1payment333333333333333333333333','1payment444444444444444444444444',], $addresses);
    }


}
