<?php

namespace App\Models;

use App\Models\Base\APIModel;
use \Exception;

/*
* Send
*/
class Send extends APIModel
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'send';

    protected static $unguarded = true;

    protected $api_attributes = ['id', 'address',];

    public function setSendDataAttribute($send_data) { $this->attributes['send_data'] = json_encode($send_data); }
    public function getSendDataAttribute() { return json_decode($this->attributes['send_data'], true); }

}
