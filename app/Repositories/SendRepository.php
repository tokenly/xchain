<?php

namespace App\Repositories;

use App\Models\Send;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Rhumsaa\Uuid\Uuid;
use Tokenly\LaravelApiProvider\Contracts\APIResourceRepositoryContract;
use Tokenly\RecordLock\Facade\RecordLock;
use Exception;

/*
* SendRepository
*/
class SendRepository implements APIResourceRepositoryContract
{

    public function create($attributes) {
        if (!isset($attributes['txid']) AND !isset($attributes['request_id'])) {
            throw new Exception("TXID or request ID is required", 1);
        }
        if (!isset($attributes['payment_address_id'])) { throw new Exception("payment_address_id is required", 1); }
        if (!isset($attributes['user_id'])) { throw new Exception("user_id is required", 1); }
        if (!isset($attributes['destination'])) { throw new Exception("destination is required", 1); }
        if (!isset($attributes['quantity_sat'])) { throw new Exception("quantity_sat is required", 1); }
        if (!isset($attributes['asset'])) { throw new Exception("asset is required", 1); }

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

    public function findByRequestID($request_id) {
        return Send::where('request_id', $request_id)->first();
    }

    public function update(Model $address, $attributes) {
        return $address->update($attributes);
    }
    public function updateByUuid($uuid, $attributes) { throw new Exception("Sends cannot be updated", 1); }

    public function delete(Model $send) {
        return $send->delete();
    }
    public function deleteByUuid($uuid) { throw new Exception("Sends cannot be deleted", 1); }


    // locks the send, then executes $func inside the lock
    //   does not modify the passed Send
    public function executeWithLockedSend(Send $send, Callable $func, $timeout=60) {
        return DB::transaction(function() use ($send, $func, $timeout) {
            return RecordLock::acquireAndExecute('xchain.send'.$send['id'], function() use ($send, $func) {
                $locked_send = Send::where('id', $send['id'])->first();
                $out = $func($locked_send);

                // update $send in memory from any changes made to $locked_send
                $send->setRawAttributes($locked_send->getAttributes());

                return $out;
            }, $timeout);
        });
    }

    // locks the send, then executes $func inside the lock
    //   does not modify the passed Send
    public function executeWithNewLockedSendByRequestID($request_id, $create_attributes, Callable $func, $timeout=60) {
        return DB::transaction(function() use ($request_id, $create_attributes, $func, $timeout) {
            try {
                $create_attributes['request_id'] = $request_id;
                $locked_send = $this->create($create_attributes);
            } catch (QueryException $e) {
                if ($e->errorInfo[0] == 23000) {
                    $locked_send = $this->findByRequestID($request_id);
                } else {
                    // some other kind of query exception
                    throw $e;
                }
            }

            return $this->executeWithLockedSend($locked_send, $func, $timeout);
        });
    }


}
