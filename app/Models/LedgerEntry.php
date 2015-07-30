<?php

namespace App\Models;

use App\Models\Base\APIModel;
use App\Models\Traits\CreatedAtDateOnly;
use Exception;

class LedgerEntry extends APIModel {

    use CreatedAtDateOnly;

    protected $api_attributes = ['id', 'created_at', 'is_credit', 'amount', 'asset',];

    protected $casts = [
        'is_credit' => 'boolean',
    ];

    const CONFIRMED   = 1;
    const UNCONFIRMED = 2;
    const SENDING     = 3;

    public static function allTypeStrings() {
        return ['confirmed', 'unconfirmed', 'sending',];
    }

    public static function typeStringToInteger($type_string) {
        switch (strtolower(trim($type_string))) {
            case 'confirmed': return self::CONFIRMED;
            case 'unconfirmed': return self::UNCONFIRMED;
            case 'sending': return self::SENDING;
        }

        throw new Exception("unknown type: $type_string", 1);
    }

    public static function validateTypeInteger($type_integer) {
        self::typeIntegerToString($type_integer);
        return $type_integer;
    }

    public static function typeIntegerToString($type_integer) {
        switch ($type_integer) {
            case self::CONFIRMED: return 'confirmed';
            case self::UNCONFIRMED: return 'unconfirmed';
            case self::SENDING: return 'sending';
        }

        throw new Exception("unknown type integer: $type_integer", 1);
    }

}
