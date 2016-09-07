<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class EventMonitorAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForEventMonitor() {
        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth();
    }

    public function testAPIAddEventMonitor()
    {
        $api_tester = $this->getAPITester();

        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $posted_vars = app('\EventMonitorHelper')->sampleVars();
        $expected_created_resource = [
            'id'              => '{{response.id}}',
            'monitorType'     => 'block',
            'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme',
        ];
        $loaded_model = $api_tester->testAddResource($posted_vars, $expected_created_resource);

        // check the user_id
        PHPUnit::assertEquals($sample_user['id'], $loaded_model['user_id']);
    }

    public function testAPIErrorsAddEventMonitor()
    {
        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme',
                    'monitorType' => 'bad',
                ],
                'expectedErrorString' => 'The selected monitor type is invalid',
            ],
            [
                'postVars' => [
                    'webhookEndpoint' => 'badbadurl',
                    'monitorType' => 'receive',
                ],
                'expectedErrorString' => 'The webhook endpoint format is invalid',
            ],
            [
                'postVars' => [
                    'monitorType' => 'receive',
                ],
                'expectedErrorString' => 'The webhook endpoint field is required',
            ],
        ]);
    }

    public function testAPIListEventMonitors() {
        $api_tester = $this->getAPITester();

        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $helper = app('\EventMonitorHelper');
        $created_monitors = [
            $helper->newSampleEventMonitor($sample_user),
            $helper->newSampleEventMonitor($sample_user, ['monitorType' => 'issuance']),
        ];

        $loaded_monitors_from_api = $api_tester->testListResources($created_monitors);

        // sanity check
        PHPUnit::assertEquals('issuance', $loaded_monitors_from_api[1]['monitorType']);
    }

    public function testAPIGetEventMonitor() {
        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $helper = app('\EventMonitorHelper');
        $created_address = $helper->newSampleEventMonitor($sample_user);

        $api_tester = $this->getAPITester();
        $loaded_monitor_from_api = $api_tester->testGetResource($created_address);
        PHPUnit::assertEquals($created_address['uuid'], $loaded_monitor_from_api['id']);
    }


    public function testAPIUpdateEventMonitor() {
        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $helper = app('\EventMonitorHelper');
        $created_address = $helper->newSampleEventMonitor($sample_user);
        $update_vars = [
            'monitorType' => 'broadcast',
        ];
        $api_tester = $this->getAPITester();
        $loaded_monitor_from_api = $api_tester->testUpdateResource($created_address, $update_vars);
        PHPUnit::assertEquals('broadcast', $loaded_monitor_from_api['monitorType']);

        $update_vars = [
            'webhookEndpoint' => 'http://xchain.tokenly.dev/notifyme2',
        ];
        $api_tester = $this->getAPITester();
        $loaded_monitor_from_api = $api_tester->testUpdateResource($created_address, $update_vars);
        PHPUnit::assertEquals('http://xchain.tokenly.dev/notifyme2', $loaded_monitor_from_api['webhookEndpoint']);
    }

    public function testAPIUpdateErrorsEventMonitor() {
        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $helper = app('\EventMonitorHelper');
        $created_address = $helper->newSampleEventMonitor($sample_user);

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
                    'webhookEndpoint' => 'bad',
                ],
                'expectedErrorString' => 'The webhook endpoint format is invalid',
            ],
        ]);
    }

    public function testAPIDeleteEventMonitor() {
        // sample user for Auth
        $sample_user = app('\UserHelper')->createSampleUser();

        $helper = app('\EventMonitorHelper');
        $created_address = $helper->newSampleEventMonitor($sample_user);
        $api_tester = $this->getAPITester();
        $api_tester->testDeleteResource($created_address);
    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        return app('SimpleAPITester', [$this->app, '/api/v1/event_monitors', app('App\Repositories\EventMonitorRepository')]);
    }


}
