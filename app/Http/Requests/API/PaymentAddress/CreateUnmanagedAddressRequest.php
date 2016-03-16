<?php

namespace App\Http\Requests\API\PaymentAddress;

use App\Http\Requests\API\PaymentAddress\CreatePaymentAddressRequest;

class CreateUnmanagedAddressRequest extends CreatePaymentAddressRequest {


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'address' => 'required',
        ];
    }


}
