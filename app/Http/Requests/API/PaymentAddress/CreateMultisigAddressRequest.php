<?php

namespace App\Http\Requests\API\PaymentAddress;

use App\Http\Requests\API\PaymentAddress\CreatePaymentAddressRequest;

class CreateMultisigAddressRequest extends CreatePaymentAddressRequest {


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'            => 'required|max:255',
            'copayerName'     => 'sometimes|max:255',
            'multisigType'    => 'required|in:2of2,2of3',
            'webhookEndpoint' => 'sometimes|url',
        ];
    }


}
