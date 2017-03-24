<?php

namespace App\Http\Requests\API\Send;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class ComposeMultisigIssuanceRequest extends APIRequest {

    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        $validator->after(function () use ($validator)
        {
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
            'asset'       => 'required|min:4|asset',
            'quantity'    => 'required|numeric|min:0|notIn:0',
            'divisible'   => 'boolean',
            'description' => 'max:41',
            'feePerKB'    => 'numeric|notIn:0',
            'feeRate'     => 'sometimes',
            'requestId'   => 'max:36',
            'message'     => 'max:255',
        ];
    }

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
