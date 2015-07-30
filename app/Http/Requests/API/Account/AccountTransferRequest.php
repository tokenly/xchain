<?php

namespace App\Http\Requests\API\Account;

use App\Http\Requests\API\Base\APIRequest;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class AccountTransferRequest extends APIRequest {



    public function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        $validator->sometimes('asset', 'required|alpha|min:3', function($input) {
            return !$input->close;
        });
        $validator->sometimes('quantity', 'required|numeric|min:0|notIn:0', function($input) {
            return !$input->close;
        });

        $validator->after(function () use ($validator)
        {
            // if close, don't allow asset or quantity

            if ($this->input('close')) {
                if ($this->input('asset')) {
                    $validator->errors()->add('asset', 'The asset field is not allowed when closing an account.');
                }
                if ($this->input('quantity')) {
                    $validator->errors()->add('quantity', 'The quantity field is not allowed when closing an account.');
                }
            } else {
                if ($this->input('asset') AND !$this->input('quantity')) {
                    $validator->errors()->add('quantity', 'The quantity field is required when specifying an asset.');
                }
                if ($this->input('quantity') AND !$this->input('asset')) {
                    $validator->errors()->add('asset', 'The asset field is required when specifying a quantity.');
                }
                if (!$this->input('quantity') AND !$this->input('asset') AND !$this->input('txid')) {
                    $validator->errors()->add('txid', 'Either quantity and asset or a transaction ID is required.');
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
            'from'     => 'required|max:127',
            'to'       => 'required|max:127',
            // 'type'     => 'sometimes|in:unconfirmed,confirmed,sending',
            'asset'    => 'sometimes|alpha|min:3',
            'quantity' => 'sometimes|numeric|min:0|notIn:0',
            'txid'     => 'sometimes|size:64',
            'close'    => 'sometimes|boolean',
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
