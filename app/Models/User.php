<?php

namespace App\Models;

use App\Models\Base\APIModel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Tokenly\LaravelApiProvider\Contracts\APIUserContract;
use \Exception;

class User extends APIModel implements AuthenticatableContract, CanResetPasswordContract, APIUserContract {

    use Authenticatable, CanResetPassword;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token'];


    protected static $unguarded = true;

    protected $api_attributes = ['id',];



    // APIUserContract
    public function getID() { return $this['id']; }
    public function getUuid() { return $this['uuid']; }
    public function getApiSecretKey() { return $this['apisecretkey']; }

}
