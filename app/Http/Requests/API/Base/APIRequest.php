<?php

namespace App\Http\Requests\API\Base;

use App\Http\Requests\Request;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;

class APIRequest extends Request {

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
