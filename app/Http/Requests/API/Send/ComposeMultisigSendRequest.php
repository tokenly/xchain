<?php

namespace App\Http\Requests\API\Send;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class ComposeMultisigSendRequest extends APIRequest {



    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        $validator->after(function () use ($validator)
        {
            // validate destination
            $destination = $this->input('destination');
            if (!AddressValidator::isValid($destination)) {
                $validator->errors()->add('destination', 'The destination was invalid.');
            }

            if (strlen($this->input('feePerKB')) AND strlen($this->input('feeRate'))) {
                $validator->errors()->add('destination', 'feePerKB and feeRate cannot both be specified');
            }
        });

        return $validator;
    }


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'destination'        => 'required',
            'quantity'           => 'numeric|notIn:0',
            'feePerKB'           => 'numeric|notIn:0',
            'feeRate'            => 'sometimes',
            'dust_size'          => 'numeric',
            'asset'              => 'required|min:3',
            'requestId'          => 'max:36',
            'message'            => 'max:255',
        ];
    }

    // address, amountSat, feePerKB, [token=BTC], message

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

}
