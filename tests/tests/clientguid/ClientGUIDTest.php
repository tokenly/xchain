<?php

use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class ClientGUIDTest extends TestCase {

    protected $useDatabase = true;

    public function testMultiplePaymentAddressSendsWithSameClientGUID()
    {

        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        // create a send with a client guid
        $api_tester = $this->getAPITester();
        $posted_vars = $this->sendHelper()->samplePostVars();
        $posted_vars['requestId'] = 'request001';
        $expected_created_resource = [
            'id'          => '{{response.id}}',
            'destination' => '{{response.destination}}',
            'asset'       => 'TOKENLY',
            'sweep'       => '{{response.sweep}}',
            'quantity'    => '{{response.quantity}}',
            'txid'        => '{{response.txid}}',
            'requestId'  => 'request001',
        ];
        $loaded_resource_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // get_asset_info followed by the send
        PHPUnit::assertCount(2, $mock_calls['xcpd']);

        // validate that a mock send was triggered
        $mock_send_call = $mock_calls['xcpd'][1];
        PHPUnit::assertEquals($payment_address['address'], $mock_send_call['args'][0]['source']);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_send_call['args'][0]['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $mock_send_call['args'][0]['quantity']);
        PHPUnit::assertEquals('TOKENLY', $mock_send_call['args'][0]['asset']);

        // try the send again with the same request_id
        $expected_resource = $loaded_resource_model;
        $posted_vars = $this->sendHelper()->samplePostVars();
        $posted_vars['requestId'] = 'request001';
        $loaded_resource_model_2 = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // does not send again
        PHPUnit::assertCount(2, $mock_calls['xcpd']);

        // second send resource is the same as the first
        PHPUnit::assertEquals($loaded_resource_model, $loaded_resource_model_2);
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

}
