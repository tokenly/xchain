<?php

use App\Models\User;
use App\Repositories\PaymentAddressRepository;

/**
*  PaymentAddressHelper
*/
class PaymentAddressHelper
{

    function __construct(PaymentAddressRepository $payment_address_repository) {
        // $this->app = $app;
        $this->payment_address_repository = $payment_address_repository;
    }


    public function createSamplePaymentAddress(User $user, $override_vars=[]) {
        return $this->payment_address_repository->createWithUser($user, array_merge([
        ], $override_vars));
    }

}