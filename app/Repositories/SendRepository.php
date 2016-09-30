<?php

namespace App\Repositories;

use App\Models\PaymentAddress;
use App\Models\Send;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tokenly\LaravelApiProvider\Contracts\APIResourceRepositoryContract;
use Tokenly\LaravelApiProvider\Filter\RequestFilter;
use Tokenly\RecordLock\Facade\RecordLock;

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
        if (!isset($attributes['destination']) AND !isset($attributes['destinations'])) {
            throw new Exception("destination is required", 1);
        }
        if (!isset($attributes['quantity_sat'])) { throw new Exception("quantity_sat is required", 1); }
        if (!isset($attributes['asset'])) { throw new Exception("asset is required", 1); }

        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }

        return Send::create($attributes);
    }

    public function findAll(RequestFilter $filter=null) {
        return Send::all();
    }

    public function findByUuid($uuid) {
        return Send::where('uuid', $uuid)->first();
    }

    public function findByTXID($txid) {
        return Send::where('txid', $txid)->first();
    }

    public function findUnsignedTransactionsByPaymentAddressID($payment_address_id) {
        return Send::where('payment_address_id', $payment_address_id)
            ->where('unsigned', 1)
            ->get();
    }

    public function findByRequestID($request_id) {
        return Send::where('request_id', $request_id)->first();
    }

    public function findByPaymentAddress(PaymentAddress $payment_address) {
        return Send::where('payment_address_id', $payment_address['id'])->get();
    }

    public function update(Model $send, $attributes) {
        return $send->update($attributes);
    }
    public function updateByUuid($uuid, $attributes) { throw new Exception("Sends cannot be updated", 1); }

    public function delete(Model $send) {
        return $send->delete();
    }
    public function deleteByUuid($uuid) { throw new Exception("Sends cannot be deleted", 1); }

    public function deleteByPaymentAddress(PaymentAddress $payment_address) {
        return 
            Send::where('payment_address_id', $payment_address['id'])
            ->delete();
    }

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
                    Log::debug("\$locked_send not found by request_id.  looking up by txid ".(isset($create_attributes['txid']) ? $create_attributes['txid'] : null));

                    // this could also be a txid conflict
                    if (!$locked_send AND isset($create_attributes['txid'])) {
                        $locked_send = $this->findByTXID($create_attributes['txid']);
                    }

                } else {
                    // some other kind of query exception
                    throw $e;
                }
            } catch (Exception $e) {
                throw $e;
                
            }

            return $this->executeWithLockedSend($locked_send, $func, $timeout);
        });
    }


}
