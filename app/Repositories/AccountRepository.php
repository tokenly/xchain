<?php

namespace App\Repositories;

use App\Models\Account;
use App\Models\PaymentAddress;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelApiProvider\Filter\IndexRequestFilter;
use Tokenly\LaravelApiProvider\Filter\Transformers;
use Tokenly\LaravelApiProvider\Repositories\APIRepository;

/*
* AccountRepository
*/
class AccountRepository extends APIRepository
{

    protected $model_type = 'App\Models\Account';

    public function create($attributes) {
        if (!isset($attributes['user_id']) OR !$attributes['user_id']) { throw new Exception("User ID is required", 1); }
        if (!isset($attributes['payment_address_id']) OR !$attributes['payment_address_id']) { throw new Exception("Payment Address ID is required", 1); }
        if (!isset($attributes['active'])) { $attributes['active'] = true; }

        return parent::create($attributes);
    }

    public function update(Model $model, $attributes) {
        if ($model['name'] == 'default') { throw new Exception("The default account may not be updated.", 400); }

        return parent::update($model, $attributes);
    }


    public function findByAddress(PaymentAddress $payment_address, $active=false) {
        $query = $this->prototype_model
            ->where('payment_address_id', $payment_address['id']);

        if ($active !== null) {
            $query->where('active', ($active ? 1 : 0));
        }

        $query->orderBy('id');

        return $query->get();
    }

    public function findByAddressAndUserID($payment_address_id, $user_id, IndexRequestFilter $filter=null) {
        $query = $this->prototype_model
            ->where('payment_address_id', $payment_address_id)
            ->where('user_id', $user_id);

        // allow filter
        if ($filter !== null) {
            $filter->filter($query);
        }

        $query->orderBy('id');

        // Log::debug("query SQL is ".$query->getQuery()->toSql()." | ".json_encode($query->getQuery()->getBindings()));

        return $query->get();
    }


    public function findByName($name, $payment_address_id) {
        $query = $this->prototype_model
            ->where('name', $name)
            ->where('payment_address_id', $payment_address_id);

        return $query->first();
    }


    public function getSearchFilterDefinition() {
        return [
            'fields' => [
                'name'   => ['field' => 'name',],
                'active' => ['field' => 'active', 'default' => 1, 'transformFn' => ['Tokenly\LaravelApiProvider\Filter\Transformers','toBooleanInteger'] ],
            ],
        ];
    }

}
