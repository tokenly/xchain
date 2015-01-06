<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class SendAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForSends() {
        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth('POST', $payment_address['uuid']);
    }

    public function testAPIErrorsSend()
    {
        // mock the xcp sender
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $api_tester->testAddErrors([

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'    => 1,
                ],
                'expectedErrorString' => 'asset field is required.',
            ],

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                ],
                'expectedErrorString' => 'quantity field is required.',
            ],

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'    => 0,
                ],
                'expectedErrorString' => 'quantity is invalid',
            ],

        ], '/'.$payment_address['uuid']);
    }

    public function testAPIAddSend()
    {
        // mock the xcp sender
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars();
        $expected_created_resource = [
            'id'          => '{{response.id}}',
            'destination' => '{{response.destination}}',
            'asset'       => 'TOKENLY',
            'sweep'       => '{{response.sweep}}',
            'quantity'    => '{{response.quantity}}',
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester =  $this->app->make('APITester', [$this->app, '/api/v1/sends', $this->app->make('App\Repositories\SendRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }



    protected function sendHelper() {
        if (!isset($this->sample_sends_helper)) { $this->sample_sends_helper = $this->app->make('SampleSendsHelper'); }
        return $this->sample_sends_helper;
    }

    protected function monitoredAddressByAddress() {
        $address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $payment_address = $address_repo->findByAddress('RECIPIENT01')->first();
        return $payment_address;

    }



}
