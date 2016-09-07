<?php

namespace App\Models;

use Tokenly\LaravelApiProvider\Model\APIModel;
use Exception;

class EventMonitor extends APIModel {

    protected $api_attributes = ['id','monitor_type','webhook_endpoint',];

    const TYPE_BLOCK     = 1;
    const TYPE_ISSUANCE  = 2;
    const TYPE_BROADCAST = 3;

    public static function allTypeStrings() {
        return ['block','issuance','broadcast',];
    }

    public static function typeStringToInteger($type_string) {
        switch (strtolower(trim($type_string))) {
            case 'block':     return self::TYPE_BLOCK;
            case 'issuance':  return self::TYPE_ISSUANCE;
            case 'broadcast': return self::TYPE_BROADCAST;
        }

        throw new Exception("unknown type: $type_string", 1);
    }

    public static function validateTypeInteger($type_integer) {
        self::typeIntegerToString($type_integer);
        return $type_integer;
    }

    public static function typeIntegerToString($type_integer) {
        switch ($type_integer) {
            case self::TYPE_BLOCK:     return 'block';
            case self::TYPE_ISSUANCE:  return 'issuance';
            case self::TYPE_BROADCAST: return 'broadcast';
        }

        throw new Exception("unknown type integer: $type_integer", 1);
    }

    // ------------------------------------------------------------------------

    public function getMonitorTypeAttribute() { return self::typeIntegerToString($this['monitor_type_int']); }
    public function setMonitorTypeAttribute($type_string) { return $this['monitor_type_int'] = self::typeStringToInteger($type_string); }

    public function setWebhookEndpointAttribute($webhook_endpoint) { $this->attributes['webhook_endpoint'] = $webhook_endpoint; }
    public function getWebhookEndpointAttribute() { return $this->attributes['webhook_endpoint']; }


}
