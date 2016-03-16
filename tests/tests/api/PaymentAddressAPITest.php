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
            'id'          => '{{response.id}}',
            'address'     => '{{response.address}}'
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

    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester = $this->app->make('SimpleAPITester', [$this->app, '/api/v1/addresses', $this->app->make('App\Repositories\PaymentAddressRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function sendHelper() {
        if (!isset($this->sample_sends_helper)) { $this->sample_sends_helper = $this->app->make('SampleSendsHelper'); }
        return $this->sample_sends_helper;
    }
}
