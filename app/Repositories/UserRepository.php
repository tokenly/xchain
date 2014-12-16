<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\APIResourceRepositoryContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\TokenGenerator\TokenGenerator;
use \Exception;

/*
* UserRepository
*/
class UserRepository implements APIResourceRepositoryContract
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
        if (!isset($attributes['apikey'])) {
            $attributes['apikey'] = $token_generator->generateToken(41, 'K');
        }

        // hash any password
        if (isset($attributes['password'])) {
            $attributes['password'] = Hash::make($attributes['password']);
        } else {
            // un-guessable random password
            $attributes['password'] = Hash::make($token_generator->generateToken(34));
        }

        return User::create($attributes);
    }

    public function findAll() {
        return User::all();
    }

    public function findByUuid($uuid) {
        return User::where('uuid', $uuid)->first();
    }

    public function updateByUuid($uuid, $attributes) {
        return $this->update($this->findByUuid($uuid), $attributes);
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

}
