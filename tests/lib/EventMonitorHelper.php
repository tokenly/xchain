<?php

use App\Models\EventMonitor;
use App\Models\User;
use App\Repositories\EventMonitorRepository;

class EventMonitorHelper  {

    function __construct(EventMonitorRepository $notification_monitor_repository) {
        $this->notification_monitor_repository = $notification_monitor_repository;
    }




    public function newSampleEventMonitor(User $user=null, $override_vars=[]) {
        if (!isset($override_vars['user_id'])) {
            if ($user === null) { $user = app('UserHelper')->getSampleUser(); }
            $override_vars['user_id'] = $user['id'];
        }
        $new_address = $this->notification_monitor_repository->create($this->sampleDBVars($override_vars));
        return $new_address;
    }

    public function sampleVars($override_vars=[]) {
        return array_merge([
            'monitorType'     => 'block',
            'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme',
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return array_merge([
            'monitor_type'     => 'block',
            'webhook_endpoint' => 'http://xchain.tokenly.dev/notifyme',
        ], $override_vars);
    }


}
