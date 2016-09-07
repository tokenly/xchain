<?php

namespace App\Http\Requests\API\EventMonitor;

use App\Http\Requests\API\EventMonitor\CreateEventMonitorRequest;
use App\Models\EventMonitor;
use Illuminate\Support\Facades\Log;

class UpdateEventMonitorRequest extends CreateEventMonitorRequest {

    public function rules()
    {
        return [
            'webhookEndpoint' => 'sometimes|url',
            'monitorType'     => 'sometimes|in:'.implode(',', EventMonitor::allTypeStrings()), // block,issuance,broadcast
        ];
    }

}
