<?php

namespace App\Commands;

use App\Commands\Command;
use App\Models\PaymentAddress;

class CreateAccount extends Command
{

    var $payment_address;
    var $attributes;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct($attributes, PaymentAddress $payment_address)
    {
        $this->payment_address = $payment_address;
        $this->attributes = $attributes;
    }
}
