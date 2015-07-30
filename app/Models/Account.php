<?php

namespace App\Models;

use App\Models\Base\APIModel;
use Exception;

class Account extends APIModel {


    protected $api_attributes = ['id', 'name', 'active', 'meta'];

    protected $casts = [
        'active' => 'boolean',
        'meta'   => 'json',
    ];


    // public function setTypeAttribute($type_string) {
    //     if (is_numeric($type_string)) {
    //         $type_integer = $type_string;
    //     } else {
    //         $type_integer = self::typeStringToInteger($type_string);
    //     }

    //     $this->attributes['type'] = $type_integer;
    // }
    // public function getTypeAttribute($type_string) {
    //     return self::typeIntegerToString($this->attributes['type']);
    // }

    // public function setNameAttribute($raw_name) {
    //     $this->attributes['name'] = strtolower(trim($raw_name));
    // }

}
