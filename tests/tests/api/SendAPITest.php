<?php

use Tokenly\CurrencyLib\CurrencyUtil;
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
                    'asset'       => 'FOOBAR',
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                ],
                'expectedErrorString' => 'sweep field is required when quantity is not present.',
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
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

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
            'txid'        => '{{response.txid}}',
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate that a mock send was triggered
        $mock_send_call = $mock_calls['xcpd'][1];
        PHPUnit::assertEquals($payment_address['address'], $mock_send_call['args'][0]['source']);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_send_call['args'][0]['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $mock_send_call['args'][0]['quantity']);
        PHPUnit::assertEquals('TOKENLY', $mock_send_call['args'][0]['asset']);
    }

    public function testAPISweep()
    {
        // mock the xcp sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars();
        unset($posted_vars['quantity']);
        $posted_vars['sweep'] = true;
        $expected_created_resource = [
            'id'          => '{{response.id}}',
            'destination' => '{{response.destination}}',
            'asset'       => 'TOKENLY',
            'sweep'       => '{{response.sweep}}',
            'quantity'    => '{{response.quantity}}',
            'txid'        => '{{response.txid}}',
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate that a mock send was triggered
        PHPUnit::assertEquals('createrawtransaction', $mock_calls['btcd'][0]['method']);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', array_keys($mock_calls['btcd'][0]['args'][1])[0]);
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
