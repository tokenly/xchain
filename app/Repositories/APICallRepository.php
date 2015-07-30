<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Bot;
use App\Models\BotEvent;
use App\Models\APICall;
use App\Models\User;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Repositories\APIRepository;
use \Exception;

/*
* APICallRepository
*/
class APICallRepository extends APIRepository
{

    protected $model_type = 'App\Models\APICall';


    public function create($attributes) {
        if (!isset($attributes['user_id']) OR !$attributes['user_id']) { throw new Exception("User ID is required", 1); }

        return parent::create($attributes);
    }


    public function update(Model $model, $attributes) { throw new Exception("Updates are not allowed", 1); }

}
