<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class MonitoredAddressAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIAddMonitoredAddress()
    {
        $api_tester = $this->getAPITester();

        $posted_vars = [
            'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'monitorType' => 'receive',
        ];
        $expected_created_resource = [
            'id'          => '{{response.id}}',
            'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'monitorType' => 'receive',
            'active'      => true
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource);
    }

    public function testAPIErrorsAddMonitoredAddress()
    {
        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'monitorType' => 'bad',
                ],
                'expectedErrorString' => 'The selected monitor type is invalid',
            ],
            [
                'postVars' => [
                'address'     => 'xBAD123456789',
                'monitorType' => 'receive',
            ],
                'expectedErrorString' => 'The address was invalid',
            ],
        ]);
    }

    public function testAPIListMonitoredAddresses() {
        $api_tester = $this->getAPITester();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_addresses = [
            $helper->createSampleMonitoredAddress(),
            $helper->createSampleMonitoredAddress(['address' => '1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz']),
        ];

        $loaded_addresses_from_api = $api_tester->testListResources($created_addresses);

        // sanity check
        PHPUnit::assertEquals('1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz', $loaded_addresses_from_api[1]['address']);
    }

    public function testAPIGetMonitoredAddress() {
        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress();

        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testGetResource($created_address);
        PHPUnit::assertEquals(true, $loaded_address_from_api['active']);
    }

    public function testAPIGetInactiveMonitoredAddress() {
        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress(['active' => false]);

        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testGetResource($created_address);
        PHPUnit::assertEquals(false, $loaded_address_from_api['active']);
    }

    public function testAPIUpdateMonitoredAddress() {
        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress();
        $update_vars = [
            'monitorType' => 'send',
            'active'      => false,
        ];

        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testUpdateResource($created_address, $update_vars);
        PHPUnit::assertEquals(false, $loaded_address_from_api['active']);

    }

    public function testAPIUpdateErrorsMonitoredAddress() {
        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress();

        $api_tester = $this->getAPITester();
        $api_tester->testUpdateErrors($created_address, [
            [
                'postVars' => [
                    'monitorType' => 'bad',
                ],
                'expectedErrorString' => 'The selected monitor type is invalid',
            ],
            [
                'postVars' => [
                    'active' => 'foobar',
                ],
                'expectedErrorString' => 'The active field must be true or false',
            ],
        ]);

    }

    public function testAPIDeleteMonitoredAddress() {
        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress();
        $api_tester = $this->getAPITester();
        $api_tester->testDeleteResource($created_address);
    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        return $this->app->make('APITester', [$this, '/api/v1/monitors', $this->app->make('App\Repositories\MonitoredAddressRepository')]);
    }


}
