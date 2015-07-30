<?php

namespace App\Http\Requests\API\Account;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class CreateAccountRequest extends APIRequest {



    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        // $validator->after(function () use ($validator)
        // {
        //     // validate address
        //     $address = $this->json('address');
        //     Log::info("\$address=$address AddressValidator::isValid($address)=".AddressValidator::isValid($address));
        //     if (!AddressValidator::isValid($address)) {
        //         $validator->errors()->add('address', 'The address was invalid.');
        //     }
        // });

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
            'addressId' => 'required|size:36',
            'name'      => 'required|max:127',
            'meta'      => 'max:65535',
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
