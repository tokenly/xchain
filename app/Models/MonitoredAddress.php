<?php

namespace App\Models;

use App\Models\Base\APIModel;
use \Exception;

/*
* MonitoredAddress
*/
class MonitoredAddress extends APIModel
{

    const MONITOR_TYPE_SEND    = 1;
    const MONITOR_TYPE_RECEIVE = 2;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'monitored_address';

    protected static $unguarded = true;

    protected $api_attributes = ['id', 'address', 'monitor_type', 'active'];


    public function setMonitorTypeAttribute($type_text) { $this->attributes['monitor_type'] = $this->monitorTypeTextToMonitorTypeID($type_text); }
    public function getMonitorTypeAttribute() { return $this->monitorTypeIDToMonitorTypeText($this->attributes['monitor_type']); }


    public function monitorTypeTextToMonitorTypeID($type_text) {
        switch ($type_text) {
            case 'send': return self::MONITOR_TYPE_SEND;
            case 'receive': return self::MONITOR_TYPE_RECEIVE;
        }
        return null;
    }

    public function monitorTypeIDToMonitorTypeText($type_id) {
        switch ($type_id) {
            case self::MONITOR_TYPE_SEND: return 'send';
            case self::MONITOR_TYPE_RECEIVE: return 'receive';
        }
        return null;
    }

}
