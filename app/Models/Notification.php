<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* Notification
*/
class Notification extends Model
{

    const STATUS_NEW     = 1;
    const STATUS_SUCCESS = 2;
    const STATUS_FAILURE = 3;

    const EVENT_SEND         = 1;
    const EVENT_RECEIVE      = 2;
    const EVENT_INVALIDATION = 3;
    const EVENT_CREDIT       = 4;
    const EVENT_DEBIT        = 5;
    const EVENT_ISSUANCE     = 6;
    const EVENT_BROADCAST    = 7;
    const EVENT_JOINED       = 8;


    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'notification';

    protected static $unguarded = true;

    public function setStatusAttribute($type_text) { $this->attributes['status'] = $this->statusTextToStatusID($type_text); }
    public function getStatusAttribute() { return $this->statusIDToStatusText($this->attributes['status']); }

    public function setEventTypeAttribute($type_text) { $this->attributes['event_type'] = $this->eventTypeTextToEventTypeID($type_text); }
    public function getEventTypeAttribute() { return $this->eventTypeIDToEventTypeText($this->attributes['event_type']); }

    public function setNotificationAttribute($notification) { $this->attributes['notification'] = json_encode($notification); }
    public function getNotificationAttribute() { return json_decode($this->attributes['notification'], true); }


    public function statusTextToStatusID($type_text) {
        switch ($type_text) {
            case 'new':     return self::STATUS_NEW;
            case 'success': return self::STATUS_SUCCESS;
            case 'failure': return self::STATUS_FAILURE;
        }
        return null;
    }

    public function statusIDToStatusText($type_id) {
        switch ($type_id) {
            case self::STATUS_NEW:     return 'new';
            case self::STATUS_SUCCESS: return 'success';
            case self::STATUS_FAILURE: return 'failure';
        }
        return null;
    }


    public function eventTypeTextToEventTypeID($type_text) {
        switch ($type_text) {
            case 'send':         return self::EVENT_SEND;
            case 'receive':      return self::EVENT_RECEIVE;
            case 'invalidation': return self::EVENT_INVALIDATION;
            case 'credit':       return self::EVENT_CREDIT;
            case 'debit':        return self::EVENT_DEBIT;
            case 'issuance':     return self::EVENT_ISSUANCE;
            case 'broadcast':    return self::EVENT_BROADCAST;
            case 'joined':       return self::EVENT_JOINED;
        }
        return null;
    }

    public function eventTypeIDToEventTypeText($type_id) {
        switch ($type_id) {
            case self::EVENT_SEND:         return 'send';
            case self::EVENT_RECEIVE:      return 'receive';
            case self::EVENT_INVALIDATION: return 'invalidation';
            case self::EVENT_CREDIT:       return 'credit';
            case self::EVENT_DEBIT:        return 'debit';
            case self::EVENT_ISSUANCE:     return 'issuance';
            case self::EVENT_BROADCAST:    return 'broadcast';
            case self::EVENT_JOINED:       return 'joined';
        }
        return null;
    }

}

