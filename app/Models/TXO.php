<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class TXO extends Model {

    const UNCONFIRMED = 1;
    const CONFIRMED   = 2;
    const SENDING     = 3;
    const SENT        = 4;


    protected $table = 'txos';

    protected static $unguarded = true;

    protected $casts = [
        'spent' => 'boolean',
        'green' => 'boolean',
    ];

    public static function allTypeStrings() {
        return ['confirmed', 'unconfirmed', 'sending', 'sent',];
    }

    public static function typeStringToInteger($type_string) {
        switch (strtolower(trim($type_string))) {
            case 'confirmed':   return self::CONFIRMED;
            case 'unconfirmed': return self::UNCONFIRMED;
            case 'sending':     return self::SENDING;
            case 'sent':        return self::SENT;
        }

        throw new Exception("unknown type: $type_string", 1);
    }

    public static function validateTypeInteger($type_integer) {
        self::typeIntegerToString($type_integer);
        return $type_integer;
    }

    public static function typeIntegerToString($type_integer) {
        switch ($type_integer) {
            case self::CONFIRMED:   return 'confirmed';
            case self::UNCONFIRMED: return 'unconfirmed';
            case self::SENDING:     return 'sending';
            case self::SENT:        return 'sent';
        }

        throw new Exception("unknown type integer: $type_integer", 1);
    }



}