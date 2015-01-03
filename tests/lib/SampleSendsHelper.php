<?php

use App\Repositories\SendRepository;
use Tokenly\CurrencyLib\CurrencyUtil;

/**
*  SampleSendsHelper
*/
class SampleSendsHelper
{

    public function __construct(SendRepository $send_repository, UserHelper $user_helper, MonitoredAddressHelper $monitored_address_helper) {
        $this->monitored_address_helper = $monitored_address_helper;
        $this->user_helper              = $user_helper;
        $this->send_repository          = $send_repository;
    }

    public function createSampleSend($override_vars=[]) {
        $user = $this->user_helper->createSampleUser();
        $monitored_address = $this->monitored_address_helper->createSampleMonitoredAddress($user);
        return $this->createSampleSendWithMonitoredAddress($monitored_address, $override_vars);
    }

    public function createSampleSendWithMonitoredAddress($monitored_address, $override_vars=[]) {
        $attributes = $this->sampleVars();
        $attributes['monitored_address_id'] = $monitored_address['id'];
        $attributes['user_id'] = $monitored_address['user_id'];
        $attributes = array_merge($attributes, $override_vars);
        return $this->send_repository->create($attributes);
    }

    public function sampleVars($override_vars=[]) {
        // apply sample post vars
        $override_vars = $this->samplePostVars($override_vars);

        $vars = array_merge([
            'txid'      => 'SAMPLETXID000000000000000000000000000000000000000000000000000001',
        ], $override_vars);

        if (isset($vars['quantity'])) {
            $vars['quantity_sat'] = CurrencyUtil::valueToSatoshis($vars['quantity']);
            unset($vars['quantity']);
        }

        return $vars;
    }

    public function samplePostVars($override_vars=[]) {
        return array_merge([
            'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'quantity'    => 100,
            'asset'       => 'TOKENLY',
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return $this->sampleVars($override_vars);
    }

}

