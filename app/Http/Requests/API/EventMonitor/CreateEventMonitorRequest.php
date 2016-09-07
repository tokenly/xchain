<?php

namespace App\Http\Requests\API\EventMonitor;

use App\Http\Requests\API\Base\APIRequest;
use App\Models\EventMonitor;
use Illuminate\Support\Facades\Log;

class CreateEventMonitorRequest extends APIRequest {



    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'webhookEndpoint' => 'required|url',
            'monitorType'     => 'required|in:'.implode(',', EventMonitor::allTypeStrings()), // block,issuance,broadcast
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
