<?php

namespace App\Models;

use Tokenly\LaravelApiProvider\Model\APIModel;
use Exception;

class PaymentAddressArchive extends PaymentAddress {

    protected $table = 'payment_address_archive';

    public function isArchived() {
        return true;
    }

}
