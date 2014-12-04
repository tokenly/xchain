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

    protected $api_attributes = ['id', 'address', 'monitor_type', 'webhook_endpoint', 'active'];


    public function setMonitorTypeAttribute($type_text) { $this->attributes['monitor_type'] = $this->monitorTypeTextToMonitorTypeID($type_text); }
    public function getMonitorTypeAttribute() { return $this->monitorTypeIDToMonitorTypeText($this->attributes['monitor_type']); }

    public function setActiveAttribute($active) { $this->attributes['active'] = $active ? 1 : 0; }
    public function getActiveAttribute() { return !!$this->attributes['active']; }

    public function setWebhookEndpointAttribute($webhook_endpoint) { $this->attributes['webhook_endpoint'] = $webhook_endpoint; }
    public function getWebhookEndpointAttribute() { return $this->attributes['webhook_endpoint']; }

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
