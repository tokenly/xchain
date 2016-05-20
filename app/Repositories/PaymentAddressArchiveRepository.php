<?php

namespace App\Repositories;

use Tokenly\LaravelApiProvider\Repositories\APIRepository;
use Exception;

/*
* PaymentAddressArchiveRepository
*/
class PaymentAddressArchiveRepository extends APIRepository
{

    protected $model_type = 'App\Models\PaymentAddressArchive';

}
