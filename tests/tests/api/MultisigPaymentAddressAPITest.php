<?php

use App\Models\TXO;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use \PHPUnit_Framework_Assert as PHPUnit;

class MultisigPaymentAddressAPITest extends TestCase {

    protected $useDatabase = true;
    // protected $useRealSQLiteDatabase = true;

    public function testAPIErrorsAddMultisigPaymentAddress()
    {
        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'name'         => '',
                    'multisigType' => '2of2',
                ],
                'expectedErrorString' => 'The name field is required',
            ],
            [
                'postVars' => [
                    'name'         => 'My Wallet One',
                    'multisigType' => 'foo',
                ],
                'expectedErrorString' => 'selected multisig type is invalid',
            ],
        ]);
    }
    public function testAPIAddMultisigPaymentAddress() {
        // install mocks
        app('CopayClientMockHelper')->mockCopayClient()->shouldIgnoreMissing();

        $api_tester = $this->getAPITester();
        $posted_vars = $this->paymentAddressHelper()->sampleMultisigPostVars([]);
        $expected_created_resource = [
            'id'              => '{{response.id}}',
            'address'         => '',
            'type'            => 'p2sh',
            'status'          => 'pending',
            'joinedMonitorId' => '{{response.joinedMonitorId}}',
        ];
        $expected_loaded_resource = $expected_created_resource;
        unset($expected_loaded_resource['joinedMonitorId']);
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, null, $expected_loaded_resource);
    }

    public function testCallCopayClientWhenAddMultisigPaymentAddress() {
        // install mocks
        app('CopayClientMockHelper')->mockTokenGenerator();
        $mock = app('CopayClientMockHelper')->mockCopayClient(); // ->shouldIgnoreMissing();
        $mock->shouldReceive('createWallet')->once()
            ->with('My multisig one', ["m" => 2, "n" => 2, "pubKey" => "026e62dcae0220e34214647a6e2467d4b6543b090ab3c4e7c9771fe07f2bd8b2bd"])
            ->andReturn('WALETID00000001');
        $mock->shouldReceive('joinWallet')->once();

        $api_tester = $this->getAPITester();
        $posted_vars = $this->paymentAddressHelper()->sampleMultisigPostVars([]);
        $expected_created_resource = [
            'id'              => '{{response.id}}',
            'address'         => '',
            'type'            => 'p2sh',
            'status'          => 'pending',
            'joinedMonitorId' => '{{response.joinedMonitorId}}',
        ];
        $expected_loaded_resource = $expected_created_resource;
        unset($expected_loaded_resource['joinedMonitorId']);
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, null, $expected_loaded_resource);
        PHPUnit::assertEquals([
            'm'            => '2',
            'n'            => '2',
            'name'         => 'My multisig one',
            'copayer_name' => 'Tokenly Testing App',
            'id'           => 'WALETID00000001',
        ], $loaded_address_model['copay_data']);

        Mockery::close();
    }


    public function testAPIAddMultisigAddressAndJoinedAddressMonitor() {
        // install mocks
        app('CopayClientMockHelper')->mockCopayClient()->shouldIgnoreMissing();

        $api_tester = $this->getAPITester();
        $posted_vars = $this->paymentAddressHelper()->sampleMultisigPostVars([]);
        $expected_created_resource = [
            'id'              => '{{response.id}}',
            'address'         => '',
            'type'            => 'p2sh',
            'status'          => 'pending',
            'joinedMonitorId' => '{{response.joinedMonitorId}}',
        ];
        $expected_loaded_resource = $expected_created_resource;
        unset($expected_loaded_resource['joinedMonitorId']);
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource, null, $expected_loaded_resource);


        // check the event monitor
        $monitor_respository = app('App\Repositories\MonitoredAddressRepository');
        $loaded_joined_monitor_models = $monitor_respository->findByAddressId($loaded_address_model['id']);
        PHPUnit::assertCount(1, $loaded_joined_monitor_models);
        $loaded_joined_monitor_model = $loaded_joined_monitor_models[0];

        PHPUnit::assertNotEmpty($loaded_joined_monitor_model);
        PHPUnit::assertEquals('joined', $loaded_joined_monitor_model['monitor_type']);
        PHPUnit::assertEquals(true, $loaded_joined_monitor_model['active']);
        PHPUnit::assertEquals('', $loaded_joined_monitor_model['address']);
        PHPUnit::assertEquals(app('UserHelper')->getSampleUser()['id'], $loaded_joined_monitor_model['user_id']);
    }

    public function testAPIRemoveMultisigAndMonitoredPaymentAddress() {
        // install mocks
        app('CopayClientMockHelper')->mockCopayClient()->shouldIgnoreMissing();

        $api_tester = $this->getAPITester();


        $posted_vars = $this->paymentAddressHelper()->sampleMultisigPostVars([]);
        $create_api_response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/multisig/addresses', $posted_vars);

        $loaded_resource_model = app('App\Repositories\PaymentAddressRepository')->findByUuid($create_api_response['id']);
        PHPUnit::assertNotEmpty($loaded_resource_model);

        $monitor_respository = app('App\Repositories\MonitoredAddressRepository');
        $loaded_joined_monitor_model = $monitor_respository->findByUuid($create_api_response['joinedMonitorId']);
        PHPUnit::assertNotEmpty($loaded_joined_monitor_model);

        // now destroy it
        $api_tester->callAPIWithAuthenticationAndReturnJSONContent('DELETE', '/api/v1/multisig/addresses/'.$loaded_resource_model['uuid'], [], 204);

        // check that it is gone
        $loaded_resource_model = app('App\Repositories\PaymentAddressRepository')->findByUuid($create_api_response['id']);
        PHPUnit::assertEmpty($loaded_resource_model);

        // check that the monitors are gone too
        PHPUnit::assertEmpty($monitor_respository->findByUuid($create_api_response['joinedMonitorId']));
    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester = app('SimpleAPITester', [$this->app, '/api/v1/multisig/addresses', app('App\Repositories\PaymentAddressRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function paymentAddressHelper() {
        if (!isset($this->payment_address_helper)) { $this->payment_address_helper = app('PaymentAddressHelper'); }
        return $this->payment_address_helper;
    }

}
