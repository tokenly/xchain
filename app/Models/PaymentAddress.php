<?php

namespace App\Models;

use App\Models\Base\APIModel;
use \Exception;

/*
* PaymentAddress
*/
class PaymentAddress extends APIModel
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'payment_address';

    protected static $unguarded = true;

    protected $api_attributes = ['id', 'address',];


}
