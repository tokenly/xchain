<?php namespace App\Http\Requests\API;

use App\Http\Requests\Request;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use LinusU\Bitcoin\AddressValidator;

class CreateMonitorRequest extends Request {



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
            'address'     => 'required',
            'monitorType' => 'required|in:send,receive',
            'active'      => 'boolean',
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

    // public function passes() {
    //     $passes = parent::passes();

    //     Log::debug('passes: '.json_encode($passes, 192));

    //     return $passes;
    // }


    // protected function failedValidation(Validator $validator) {
    //     Log::debug("failed: ".get_class($validator)." messages: ".json_encode($validator->messages(), 192));
    //     return parent::failedValidation($validator);
    // }


    protected function failedValidation(Validator $validator)
    {
        $errors = $this->formatErrors($validator);
        $json_errors_list = [];
        foreach($errors as $field => $errors_list) {
            $json_errors_list = array_merge($json_errors_list, $errors_list);
        }

        $json_response = [
            'errors' => $json_errors_list,
        ];
        $response = new JsonResponse($json_response, 400);

        throw new HttpResponseException($response);
    }

}
