<?php

namespace App\Models;

use App\Models\Base\APIModel;
use App\Models\Traits\CreatedAtDateOnly;

class APICall extends APIModel {

    use CreatedAtDateOnly;

    protected $table = 'api_calls';

    protected $api_attributes = ['id', 'created_at', 'details',];

    protected $casts = [
        'details' => 'json',
    ];


}
