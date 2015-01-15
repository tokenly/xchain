<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\PaymentAddressSender;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Controllers\Helpers\APIControllerHelper;
use App\Http\Requests\API\Send\CreateSendRequest;
use App\Providers\EventLog\Facade\EventLog;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Illuminate\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tokenly\BitcoinPayer\Exception\PaymentException;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CurrencyLib\CurrencyUtil;

class SendController extends APIController {

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

        // send
        $request_attributes = $request->only(array_keys($request->rules()));
        EventLog::log('send.requested', $request_attributes);
        $float_fee = isset($request_attributes['fee']) ? $request_attributes['fee'] : null;
        $multisig_dust_size = isset($request_attributes['multisig_dust_size']) ? $request_attributes['multisig_dust_size'] : null;
        $is_sweep = isset($request_attributes['sweep']) ? !!$request_attributes['sweep'] : false;
        if ($is_sweep) {
            try {
                list($txid, $float_balance_sent) = $address_sender->sweepBTC($payment_address, $request_attributes['destination'], $float_fee);
                $quantity_sat = CurrencyUtil::valueToSatoshis($float_balance_sent);
            } catch (PaymentException $e) {
                EventLog::logError('error.sweep', $e);
                return new JsonResponse(['message' => $e->getMessage()], 500); 
            }
        } else {
            try {
                $txid = $address_sender->send($payment_address, $request_attributes['destination'], $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $multisig_dust_size);
                $quantity_sat = CurrencyUtil::valueToSatoshis($request_attributes['quantity']);
            } catch (PaymentException $e) {
                EventLog::logError('error.sweep', $e);
                return new JsonResponse(['message' => $e->getMessage()], 500); 
            }
        }

        // 'destination' => 'required',
        // 'quantity'    => 'float',
        // 'asset'       => 'require_without:sweep|string',

        $attributes = [];
        $attributes['user_id']            = $user['id'];
        $attributes['payment_address_id'] = $payment_address['id'];
        $attributes['sent']               = time();
        $attributes['destination']        = $request_attributes['destination'];
        $attributes['quantity_sat']       = $quantity_sat;
        $attributes['fee']                = $request_attributes['fee'];
        $attributes['multisig_dust_size'] = $request_attributes['multisig_dust_size'];
        $attributes['asset']              = $request_attributes['asset'];
        $attributes['is_sweep']           = $is_sweep;
        $attributes['txid']               = $txid;
        Log::debug('$attributes='.json_encode($attributes, 192));

        EventLog::log('send.complete', $attributes);

        return $helper->store($send_respository, $attributes);
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
