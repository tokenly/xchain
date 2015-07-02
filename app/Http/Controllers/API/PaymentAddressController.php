<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use App\Http\Requests\API\PaymentAddress\CreatePaymentAddressRequest;
use App\Http\Requests\API\PaymentAddress\UpdatePaymentAddressRequest;
use Tokenly\LaravelEventLog\Facade\EventLog;
use App\Repositories\PaymentAddressRepository;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Log;

class PaymentAddressController extends APIController {

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository)
    {
        return $helper->index($payment_address_respository);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(APIControllerHelper $helper, CreatePaymentAddressRequest $request, PaymentAddressRepository $payment_address_respository, Guard $auth)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $attributes = $request->only(array_keys($request->rules()));
        $attributes['user_id'] = $user['id'];

        $out = $helper->store($payment_address_respository, $attributes);
        EventLog::log('paymentAddress.created', json_decode($out->getContent(), true));
        return $out;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, $id)
    {
        return $helper->show($payment_address_respository, $id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(APIControllerHelper $helper, UpdatePaymentAddressRequest $request, PaymentAddressRepository $payment_address_respository, $id)
    {
        return $helper->update($payment_address_respository, $id, $request->only(array_keys($request->rules())));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, $id)
    {
        return $helper->destroy($payment_address_respository, $id);
    }

}
