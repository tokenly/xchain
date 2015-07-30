<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class MonitoredAddressAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForMonitoredAddress() {
        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth();
    }

    public function testAPIAddMonitoredAddress()
    {
        $api_tester = $this->getAPITester();

        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $posted_vars = $this->app->make('\MonitoredAddressHelper')->sampleVars();
        $expected_created_resource = [
            'id'              => '{{response.id}}',
            'address'         => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'monitorType'     => 'receive',
            'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme',
            'active'          => true
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource);

        // check the user_id
        PHPUnit::assertEquals($sample_user['id'], $loaded_address_model['user_id']);
    }

    public function testAPIErrorsAddMonitoredAddress()
    {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme',
                    'monitorType' => 'bad',
                ],
                'expectedErrorString' => 'The selected monitor type is invalid',
            ],
            [
                'postVars' => [
                    'address'     => 'xBAD123456789',
                    'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme',
                    'monitorType' => 'receive',
                ],
                'expectedErrorString' => 'The address was invalid',
            ],
            [
                'postVars' => [
                    'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'webhookEndpoint' => 'badbadurl',
                    'monitorType' => 'receive',
                ],
                'expectedErrorString' => 'The webhook endpoint format is invalid',
            ],
            [
                'postVars' => [
                    'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'monitorType' => 'receive',
                ],
                'expectedErrorString' => 'The webhook endpoint field is required',
            ],
        ]);
    }

    public function testAPIListMonitoredAddresses() {
        $api_tester = $this->getAPITester();

        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_addresses = [
            $helper->createSampleMonitoredAddress($sample_user),
            $helper->createSampleMonitoredAddress($sample_user, ['address' => '1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz']),
        ];

        $loaded_addresses_from_api = $api_tester->testListResources($created_addresses);

        // sanity check
        PHPUnit::assertEquals('1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz', $loaded_addresses_from_api[1]['address']);
    }

    public function testAPIGetMonitoredAddress() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress($sample_user);

        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testGetResource($created_address);
        PHPUnit::assertEquals(true, $loaded_address_from_api['active']);
    }

    public function testAPIGetInactiveMonitoredAddress() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress($sample_user, ['active' => false]);

        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testGetResource($created_address);
        PHPUnit::assertEquals(false, $loaded_address_from_api['active']);
    }

    public function testAPIUpdateMonitoredAddress() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress($sample_user);
        $update_vars = [
            'monitorType' => 'send',
            'active'      => false,
        ];
        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testUpdateResource($created_address, $update_vars);
        PHPUnit::assertEquals(false, $loaded_address_from_api['active']);

        $update_vars = [
            'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme2',
        ];
        $api_tester = $this->getAPITester();
        $loaded_address_from_api = $api_tester->testUpdateResource($created_address, $update_vars);
        PHPUnit::assertEquals('http://xchain.tokenly.dev/notifyme2', $loaded_address_from_api['webhookEndpoint']);
    }

    public function testAPIUpdateErrorsMonitoredAddress() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress($sample_user);

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
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $helper = $this->app->make('\MonitoredAddressHelper');
        $created_address = $helper->createSampleMonitoredAddress($sample_user);
        $api_tester = $this->getAPITester();
        $api_tester->testDeleteResource($created_address);
    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        return $this->app->make('SimpleAPITester', [$this->app, '/api/v1/monitors', $this->app->make('App\Repositories\MonitoredAddressRepository')]);
    }


}
