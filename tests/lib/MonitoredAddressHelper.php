<?php

use App\Repositories\MonitoredAddressRepository;

/**
*  MonitoredAddressHelper
*/
class MonitoredAddressHelper
{

    function __construct(MonitoredAddressRepository $monitored_address_repository) {
        // $this->app = $app;
        $this->monitored_address_repository = $monitored_address_repository;
    }


    public function createSampleMonitoredAddress($override_vars=[]) {
        return $this->monitored_address_repository->create(array_merge([
            'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'monitorType' => 'receive',            
        ], $override_vars));
    }

}