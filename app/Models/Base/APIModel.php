<?php

namespace App\Models\Base;

use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* APIModel
*/
class APIModel extends Model
{

    protected $api_attributes = [];

    protected static $unguarded = true;

    public function serializeForAPI() {
        $out = $this->attributesToArray();

        $out = [];
        foreach($this->api_attributes as $api_attribute) {
            if ($api_attribute == 'id' AND isset($this['uuid'])) {
                $out['id'] = $this['uuid'];
            } else {
                $out[camel_case($api_attribute)] = $this[$api_attribute];
            }
        }

        return $out;
    }

}
