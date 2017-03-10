<?php

namespace App\Http\Requests\API\Send;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LinusU\Bitcoin\AddressValidator;
use Tokenly\CurrencyLib\CurrencyUtil;

class CreateMultiSendRequest extends APIRequest {



    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        $validator->after(function () use ($validator)
        {
            // validate destinations
            $destinations = $this->json('destinations');
            if ($destinations) {
                if (is_array($destinations)) {
                    $offset = 0;
                    foreach($destinations as $destination) {
                        if (!isset($destination['address']) OR !isset($destination['amount'])) {
                            $validator->errors()->add('destinations', 'Missing address or amount for destination '.($offset+1).'.');
                            continue;
                        }
                        if (!AddressValidator::isValid($destination['address'])) {
                            $validator->errors()->add('destinations', 'The address for destination '.($offset+1).' was invalid.');
                        }
                        if (!is_numeric($destination['amount']) OR CurrencyUtil::valueToSatoshis($destination['amount']) <= 0) {
                            $validator->errors()->add('destinations', 'The amount for destination '.($offset+1).' was invalid.');
                        }

                        ++$offset;
                    }
                } else {
                    $validator->errors()->add('destinations', 'The destinations were invalid.');
                }
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
            'destinations' => 'required',
            'fee'          => 'numeric',
            'feeRate'      => 'sometimes',
            'requestId'    => 'max:36',
            'account'      => 'max:127',
            'unconfirmed'  => 'boolean',
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
