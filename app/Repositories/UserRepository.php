<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\LaravelApiProvider\Contracts\APIResourceRepositoryContract;
use Tokenly\LaravelApiProvider\Contracts\APIUserRepositoryContract;
use Tokenly\LaravelApiProvider\Filter\RequestFilter;
use Tokenly\TokenGenerator\TokenGenerator;
use \Exception;

/*
* UserRepository
*/
class UserRepository implements APIResourceRepositoryContract, APIUserRepositoryContract
{

    public function __construct(BitcoinAddressGenerator $address_generator) {
        $this->address_generator = $address_generator;
    }

    public function create($attributes) {
        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }

        $token_generator = new TokenGenerator();
        
        // create a token
        if (!isset($attributes['apitoken'])) {
            $attributes['apitoken'] = $token_generator->generateToken(16, 'T');
        }
        if (!isset($attributes['apisecretkey'])) {
            $attributes['apisecretkey'] = $token_generator->generateToken(40, 'K');
        }

        // hash any password
        if (isset($attributes['password']) AND strlen($attributes['password'])) {
            $attributes['password'] = Hash::make($attributes['password']);
        } else {
            // un-guessable random password
            $attributes['password'] = Hash::make($token_generator->generateToken(34));
        }

        return User::create($attributes);
    }

    public function findAll(RequestFilter $filter=null) {
        return User::all();
    }

    public function findByUuid($uuid) {
        return User::where('uuid', $uuid)->first();
    }

    public function findByEmail($email) {
        return User::where('email', $email)->first();
    }

    public function findByID($id) {
        return User::find($id);
    }

    public function findByAPIToken($api_token) {
        return User::where('apitoken', $api_token)->first();
    }

    public function findWithWebhookEndpoint($columns=['*']) {
        return User::where('webhook_endpoint', '!=', '')->get($columns);
    }

    public function updateByUuid($uuid, $attributes) {
        $model = $this->findByUuid($uuid);
        if (!$model) { throw new Exception("Unable to find model for uuid $uuid", 1); }
        return $this->update($model, $attributes);
    }

    public function update(Model $address, $attributes) {
        return $address->update($attributes);
    }

    public function deleteByUuid($uuid) {
        if ($address = self::findByUuid($uuid)) {
            return self::delete($address);
        }
        return false;
    }

    public function delete(Model $address) {
        return $address->delete();
    }


    public function deleteAll() {
        return User::truncate();
    }

}
