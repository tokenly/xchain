<?php

use App\Models\Block;
use App\Repositories\NotificationRepository;

/**
*  NotificationHelper
*/
class NotificationHelper
{

    function __construct(NotificationRepository $notification_repository, \MonitoredAddressHelper $monitored_address_helper) {
        $this->notification_repository  = $notification_repository;
        $this->monitored_address_helper = $monitored_address_helper;
    }


    public function createSampleNotification($address_model=null, $override_vars=[]) {
        if ($address_model === null) {
            $address_model = $this->monitored_address_helper->createSampleMonitoredAddress();
        }

        return $this->notification_repository->createForMonitoredAddress($address_model, array_merge($this->sampleVars(), $override_vars));
    }

    public function sampleVars($override_vars=[], Block $block=null) {
        if ($block == null) {
            $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');
        }

        return array_merge([
            'confirmations' => 0,
            'txid'          => 'cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741',
            'notification'  => ['foo' => 'bar'],
            'block_id'      => $block['id'],
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return $this->sampleVars($override_vars);
    }

}