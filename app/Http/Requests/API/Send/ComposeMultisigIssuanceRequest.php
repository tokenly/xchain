<?php

namespace App\Http\Requests\API\Send;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class ComposeMultisigIssuanceRequest extends APIRequest {


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
