<?php

namespace App\Http\Requests\API\Send;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class CreateSendRequest extends APIRequest {



    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        $validator->after(function () use ($validator)
        {
            // validate destination
            $destination = $this->json('destination');
            if (!AddressValidator::isValid($destination)) {
                $validator->errors()->add('destination', 'The destination was invalid.');
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
            'fee'                => 'numeric',
            'multisig_dust_size' => 'numeric',
            'asset'              => 'required|alpha|min:3',
            'sweep'              => 'required_without:quantity|boolean',
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
