<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* Notification
*/
class Notification extends Model
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'notification';

    protected static $unguarded = true;


}
