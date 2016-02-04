<?php

namespace App\Http\Requests\API\Send;

use App\Http\Requests\API\Send\CreateSendRequest;

class ComposeSendRequest extends CreateSendRequest {

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = parent::rules();
        unset($rules['sweep']);

        return $rules;
    }


}
