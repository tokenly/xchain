<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\PaymentAddressSender;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Send\CreateSendRequest;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Illuminate\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinPayer\Exception\PaymentException;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class SendController extends APIController {

    const SEND_LOCK_TIMEOUT = 3600; // 1 hour

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function create(APIControllerHelper $helper, CreateSendRequest $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, $id)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the address
        $payment_address = $payment_address_respository->findByUuid($id);
        if (!$payment_address) { return new JsonResponse(['message' => 'address not found'], 404); }

        // make sure this address belongs to this user
        if ($payment_address['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to send from this address'], 403); }

        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        // create a send and lock it immediately
        $request_id = isset($request_attributes['requestId']) ? $request_attributes['requestId'] : Uuid::uuid4()->toString();

        $create_attributes = [];
        $create_attributes['user_id']            = $user['id'];
        $create_attributes['payment_address_id'] = $payment_address['id'];
        $create_attributes['destination']        = $request_attributes['destination'];
        $create_attributes['quantity_sat']       = CurrencyUtil::valueToSatoshis($request_attributes['quantity']);
        $create_attributes['asset']              = $request_attributes['asset'];
        return $send_respository->executeWithNewLockedSendByRequestID($request_id, $create_attributes, function($locked_send) use ($request_attributes, $payment_address, $user, $helper, $send_respository, $address_sender) {

            // if a send already exists by this request_id, just return it
            if (isset($locked_send['txid']) && strlen($locked_send['txid'])) {
                EventLog::log('send.alreadyFound', $locked_send);
                return $helper->transformResourceForOutput($locked_send);
            }

            // send
            EventLog::log('send.requested', $request_attributes);
            $float_fee = isset($request_attributes['fee']) ? $request_attributes['fee'] : null;
            $multisig_dust_size = isset($request_attributes['multisig_dust_size']) ? $request_attributes['multisig_dust_size'] : null;
            $dust_size = isset($request_attributes['dust_size']) ? $request_attributes['dust_size'] : null;
            $is_sweep = isset($request_attributes['sweep']) ? !!$request_attributes['sweep'] : false;
            if ($is_sweep) {
                try {
                    list($txid, $float_balance_sent) = $address_sender->sweepAllAssets($payment_address, $request_attributes['destination'], $float_fee);
                    $quantity_sat = CurrencyUtil::valueToSatoshis($float_balance_sent);
                } catch (PaymentException $e) {
                    EventLog::logError('error.sweep', $e);
                    return new JsonResponse(['message' => $e->getMessage()], 500); 
                }
            } else {
                try {
                    $txid = $address_sender->send($payment_address, $request_attributes['destination'], $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $dust_size, $multisig_dust_size);
                    $quantity_sat = CurrencyUtil::valueToSatoshis($request_attributes['quantity']);
                } catch (PaymentException $e) {
                    EventLog::logError('error.pay', $e);
                    return new JsonResponse(['message' => $e->getMessage()], 500); 
                }
            }

            $attributes = [];
            $attributes['user_id']            = $user['id'];
            $attributes['payment_address_id'] = $payment_address['id'];
            $attributes['sent']               = time();
            $attributes['destination']        = $request_attributes['destination'];
            $attributes['quantity_sat']       = $quantity_sat;
            $attributes['fee']                = $request_attributes['fee'];
            $attributes['multisig_dust_size'] = $request_attributes['multisig_dust_size'];
            $attributes['dust_size']          = $request_attributes['dust_size'];
            $attributes['asset']              = $request_attributes['asset'];
            $attributes['is_sweep']           = $is_sweep;
            $attributes['txid']               = $txid;

            EventLog::log('send.complete', $attributes);

            // update and send response
            $send_respository->update($locked_send, $attributes);
            return $helper->buildJSONResponse($locked_send->serializeForAPI());
        }, self::SEND_LOCK_TIMEOUT);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIControllerHelper $helper, SendRepository $send_respository, $id)
    {
        return $helper->show($send_respository, $id);
    }



}
