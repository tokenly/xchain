<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class PaymentAddressAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForPaymentAddress() {
        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth();
    }


    public function testAPIAddPaymentAddress()
    {
        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars();
        $expected_created_resource = [
            'id'      => '{{response.id}}',
            'address' => '{{response.address}}',
            'type'    => 'p2pkh',
            'status'  => 'ready',
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource);
    }


    public function testAPIErrorsAddPaymentAddress()
    {
        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'address'     => 'xBAD123456789',
                    'monitorType' => 'receive',
                ],
                'expectedErrorString' => 'The address was invalid',
            ],
        ]);
    }


    public function testAPIListPaymentAddresses() {
        $api_tester = $this->getAPITester();
        $user = $this->app->make('\UserHelper')->getSampleUser();

        $helper = $this->app->make('\PaymentAddressHelper');
        $created_addresses = [
            $helper->createSamplePaymentAddress($user),
            $helper->createSamplePaymentAddress($user),
        ];

        $loaded_addresses_from_api = $api_tester->testListResources($created_addresses);

        // sanity check
        PHPUnit::assertEquals($created_addresses[0]['address'], $loaded_addresses_from_api[0]['address']);
        PHPUnit::assertEquals($created_addresses[1]['address'], $loaded_addresses_from_api[1]['address']);
    }


    public function testAPIGetPaymentAddress() {
        // get api tester first
        $api_tester = $this->getAPITester();

        $user = $this->app->make('\UserHelper')->getSampleUser();
        $helper = $this->app->make('\PaymentAddressHelper');
        $created_address = $helper->createSamplePaymentAddress($user);

        $loaded_address_from_api = $api_tester->testGetResource($created_address);
        PHPUnit::assertEquals($created_address['address'], $loaded_address_from_api['address']);
    }


    // public function testAPIUpdatePaymentAddress() {
    //     $helper = $this->app->make('\PaymentAddressHelper');
    //     $created_address = $helper->createSamplePaymentAddress();
    //     $update_vars = [
    //         'monitorType' => 'send',
    //         'active'      => false,
    //     ];

    //     $api_tester = $this->getAPITester();
    //     $loaded_address_from_api = $api_tester->testUpdateResource($created_address, $update_vars);
    //     PHPUnit::assertEquals(false, $loaded_address_from_api['active']);

    // }

/*
    public function testAPIUpdateErrorsPaymentAddress() {
        $helper = $this->app->make('\PaymentAddressHelper');
        $created_address = $helper->createSamplePaymentAddress();

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
*/


/*
    public function testAPIDeletePaymentAddress() {
        $helper = $this->app->make('\PaymentAddressHelper');
        $created_address = $helper->createSamplePaymentAddress();
        $api_tester = $this->getAPITester();
        $api_tester->testDeleteResource($created_address);
    }
*/

    public function testAPIRemoveNotificationsForManagedAndMonitoredPaymentAddress() {
        // install the counterparty client mock
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();

        $posted_vars = [];
        $create_api_response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/addresses', $posted_vars);

        $payment_address_model = app('App\Repositories\PaymentAddressRepository')->findByUuid($create_api_response['id']);
        $original_payment_address_model = $payment_address_model;
        PHPUnit::assertNotEmpty($payment_address_model);

        $monitor_respository = app('App\Repositories\MonitoredAddressRepository');
        // create monitors
        $loaded_receive_monitor_model = app('MonitoredAddressHelper')->createSampleMonitoredAddress(null, ['monitorType' => 'receive', 'address' => $payment_address_model['address']]);
        $loaded_send_monitor_model    = app('MonitoredAddressHelper')->createSampleMonitoredAddress(null, ['monitorType' => 'send', 'address' => $payment_address_model['address']]);

        // create a notification for this address
        app('NotificationHelper')->createSampleNotification($loaded_receive_monitor_model);

        // now destroy it
        $api_tester->callAPIWithAuthenticationAndReturnJSONContent('DELETE', '/api/v1/addresses/'.$payment_address_model['uuid'], [], 204);

        // check that it is gone
        $payment_address_model = app('App\Repositories\PaymentAddressRepository')->findByUuid($create_api_response['id']);
        PHPUnit::assertEmpty($payment_address_model);

        // check that the monitors are gone too
        PHPUnit::assertEmpty($monitor_respository->findById($loaded_receive_monitor_model['id']));
        PHPUnit::assertEmpty($monitor_respository->findById($loaded_send_monitor_model['id']));

        // check that notifications are gone
        PHPUnit::assertCount(0, app('App\Repositories\NotificationRepository')->findByMonitoredAddressId($original_payment_address_model['id']));
    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester = $this->app->make('SimpleAPITester', [$this->app, '/api/v1/addresses', $this->app->make('App\Repositories\PaymentAddressRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function paymentAddressHelper() {
        if (!isset($this->payment_address_helper)) { $this->payment_address_helper = app('PaymentAddressHelper'); }
        return $this->payment_address_helper;
    }

    protected function sendHelper() {
        if (!isset($this->sample_sends_helper)) { $this->sample_sends_helper = $this->app->make('SampleSendsHelper'); }
        return $this->sample_sends_helper;
    }
}
