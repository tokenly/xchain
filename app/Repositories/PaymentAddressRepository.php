<?php

namespace App\Repositories;

use App\Models\PaymentAddress;
use App\Models\User;
use App\Repositories\Contracts\APIResourceRepositoryContract;
use Illuminate\Database\Eloquent\Model;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\TokenGenerator\TokenGenerator;
use \Exception;

/*
* PaymentAddressRepository
*/
class PaymentAddressRepository implements APIResourceRepositoryContract
{

    public function __construct(BitcoinAddressGenerator $address_generator) {
        $this->address_generator = $address_generator;
    }

    public function createWithUser(User $user, $attributes) {
        if (!isset($user['id']) OR !$user['id']) { throw new Exception("User ID is required", 1); }
        $attributes['user_id'] = $user['id'];

        return $this->create($attributes);
    }

    public function create($attributes) {
        // require user_id
        if (!isset($attributes['user_id']) OR !$attributes['user_id']) { throw new Exception("User ID is required", 1); }

        // create a uuid
        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }

        // create a token
        $token_generator = new TokenGenerator();
        $token = $token_generator->generateToken(40, 'A');
        $attributes['private_key_token'] = $token;

        // create an address
        $new_address = $this->address_generator->publicAddress($token);
        $attributes['address'] = $new_address;

        return PaymentAddress::create($attributes);
    }

    public function findAll() {
        return PaymentAddress::all();
    }

    public function findByUuid($uuid) {
        return PaymentAddress::where('uuid', $uuid)->first();
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

    public function findByAddress($address) {
        return PaymentAddress::where('address', $address);
    }

    public function findByAddresses($addresses) {
        return PaymentAddress::whereIn('address', $addresses);
    }

}
