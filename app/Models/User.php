<?php

namespace App\Models;

use App\Models\Base\APIModel;
use \Exception;

/*
* User
*/
class User extends APIModel
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    protected static $unguarded = true;

    protected $api_attributes = ['id',];


}
