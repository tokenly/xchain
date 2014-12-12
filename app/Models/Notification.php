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

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'notification';

    protected static $unguarded = true;

    public function setStatusAttribute($type_text) { $this->attributes['status'] = $this->statusTextToStatusID($type_text); }
    public function getStatusAttribute() { return $this->statusIDToStatusText($this->attributes['status']); }

    public function setNotificationAttribute($notification) { $this->attributes['notification'] = json_encode($notification); }
    public function getNotificationAttribute() { return json_decode($this->attributes['notification'], true); }

    public function statusTextToStatusID($type_text) {
        switch ($type_text) {
            case 'new': return self::STATUS_NEW;
            case 'success': return self::STATUS_SUCCESS;
            case 'failure': return self::STATUS_FAILURE;
        }
        return null;
    }

    public function statusIDToStatusText($type_id) {
        switch ($type_id) {
            case self::STATUS_NEW: return 'new';
            case self::STATUS_SUCCESS: return 'success';
            case self::STATUS_FAILURE: return'failure';
        }
        return null;
    }

}

