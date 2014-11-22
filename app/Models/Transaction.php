<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* Transaction
*/
class Transaction extends Model
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'transaction';

    protected static $unguarded = true;


}
