<?php

namespace App\Http\Requests\API\Monitor;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class CreateMonitorRequest extends APIRequest {



    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        $validator->after(function () use ($validator)
        {
            // validate address
            $address = $this->get('address');
            if (!AddressValidator::isValid($address)) {
                $validator->errors()->add('address', 'The address was invalid.');
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
            'address'         => 'required',
            'webhookEndpoint' => 'required|url',
            'monitorType'     => 'required|in:send,receive',
            'active'          => 'boolean',
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
