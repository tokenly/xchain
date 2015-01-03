<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\PaymentAddressSender;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Controllers\Helpers\APIControllerHelper;
use App\Http\Requests\API\Send\CreateSendRequest;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Illuminate\Auth\Guard;
use Illuminate\Http\JsonResponse;
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
        $monitored_address = $payment_address_respository->findByUuid($id);
        if (!$monitored_address) { return new JsonResponse(['message' => 'address not found'], 404); }

        // make sure this address belongs to this user
        if ($monitored_address['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to send from this address'], 403); }

        // send
        $request_attributes = $request->only(array_keys($request->rules()));
        $is_sweep = isset($request_attributes['is_sweep']) ? !!$request_attributes['is_sweep'] : false;
        $txid = $address_sender->send($monitored_address, $request_attributes['destination'], $request_attributes['quantity'], $request_attributes['asset']);
        $quantity_sat = CurrencyUtil::valueToSatoshis($request_attributes['quantity']);

        // 'destination' => 'required',
        // 'quantity'    => 'float',
        // 'asset'       => 'require_without:sweep|string',

        $attributes = [];
        $attributes['user_id']              = $user['id'];
        $attributes['monitored_address_id'] = $monitored_address['id'];
        $attributes['sent']                 = time();
        $attributes['destination']          = $request_attributes['destination'];
        $attributes['quantity_sat']         = $quantity_sat;
        $attributes['asset']                = $request_attributes['asset'];
        $attributes['is_sweep']             = $is_sweep;
        $attributes['txid']                 = $txid;

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
