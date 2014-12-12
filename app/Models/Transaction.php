<?php

namespace App\Models;

use App\Models\Base\APIModel;
use \Exception;

/*
* Transaction
*/
class Transaction extends APIModel
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'transaction';

    protected static $unguarded = true;

    public function setParsedTxAttribute($parsed_tx) { $this->attributes['parsed_tx'] = json_encode($parsed_tx); }
    public function getParsedTxAttribute() { return json_decode($this->attributes['parsed_tx'], true); }

}
