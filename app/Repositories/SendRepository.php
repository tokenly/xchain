<?php

namespace App\Repositories;

use App\Models\Send;
use Tokenly\LaravelApiProvider\Contracts\APIResourceRepositoryContract;
use Illuminate\Database\Eloquent\Model;
use Rhumsaa\Uuid\Uuid;
use \Exception;

/*
* SendRepository
*/
class SendRepository implements APIResourceRepositoryContract
{

    public function create($attributes) {
        if (!isset($attributes['txid'])) { throw new Exception("TXID is required", 1); }
        if (!isset($attributes['payment_address_id'])) { throw new Exception("payment_address_id is required", 1); }
        if (!isset($attributes['user_id'])) { throw new Exception("user_id is required", 1); }

        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }

        return Send::create($attributes);
    }

    public function findAll() {
        return Send::all();
    }

    public function findByUuid($uuid) {
        return Send::where('uuid', $uuid)->first();
    }

    public function findByTXID($txid) {
        return Send::where('txid', $txid)->first();
    }

    public function update(Model $address, $attributes) {
        return $address->update($attributes);
    }
    public function updateByUuid($uuid, $attributes) { throw new Exception("Sends cannot be updated", 1); }

    public function delete(Model $send) {
        return $send->delete();
    }
    public function deleteByUuid($uuid) { throw new Exception("Sends cannot be deleted", 1); }



}
