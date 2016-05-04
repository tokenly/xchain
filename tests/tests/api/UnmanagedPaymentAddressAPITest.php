<?php

use App\Models\TXO;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use \PHPUnit_Framework_Assert as PHPUnit;

class UnmanagedPaymentAddressAPITest extends TestCase {

    protected $useDatabase = true;
    protected $useRealSQLiteDatabase = true;

    public function testAPIAddUnmanagedPaymentAddress() {
        // install the counterparty client mock
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();

        list($private_key, $address) = $this->generateUnmanagedAddress();

        $posted_vars = $this->paymentAddressHelper()->samplePostVars(['address' => $address]);
        $expected_created_resource = [
            'id'      => '{{response.id}}',
            'address' => $address,
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource);
    }

    public function testAPIErrorsAddUnmanagedPaymentAddress()
    {
        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'address' => 'xBAD123456789',
                ],
                'expectedErrorString' => 'The address was invalid',
            ],
            [
                'postVars' => [
                    'address'     => '',
                ],
                'expectedErrorString' => 'The address field is required',
            ],
        ]);
    }

    public function testNewUnmanagedPaymentAddressLoadsExistingBalances() {
        $api_tester = $this->getAPITester();

        // install the counterparty client mock
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($private_key, $address) = $this->generateUnmanagedAddress();

        $posted_vars = $this->paymentAddressHelper()->samplePostVars(['address' => $address]);
        $expected_created_resource = [
            'id'      => '{{response.id}}',
            'address' => $address,
        ];
        $loaded_address_model = $api_tester->testAddResource($posted_vars, $expected_created_resource);

        // load the balances for the address
        $parameters = ['type' => 'confirmed'];
        $api_response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/accounts/balances/'.$loaded_address_model['uuid'], $parameters);
        // echo "\$api_response: ".json_encode($api_response, 192)."\n";

        // make sure the daemons were called
        PHPUnit::assertNotEmpty($mock_calls['xcpd'], "No calls to xcpd daemon found");
        PHPUnit::assertNotEmpty($mock_calls['btcd'], "No calls to btcd daemon found");

        PHPUnit::assertArrayHasKey('BTC', $api_response[0]['balances'], 'BTC balance was not found.');
        PHPUnit::assertEquals(0.235, $api_response[0]['balances']['BTC']);
        PHPUnit::assertEquals(100, $api_response[0]['balances']['FOOCOIN']);
        PHPUnit::assertEquals(200, $api_response[0]['balances']['BARCOIN']);
    }


    public function testAPICreateUnmanagedAddressComposedTransaction() {
        // mock asset cache
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();

        list($private_key, $address) = $this->generateUnmanagedAddress();
        $address_model = $this->addUnamangedAddress($address);

        $parameters = app('SampleSendsHelper')->samplePostVars();
        $send_details = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters);

        PHPUnit::assertNotEmpty($send_details['unsignedTx']);
        PHPUnit::assertArrayNotHasKey('txid', $send_details);
    }


    public function testAPILockComposedTransaction() {
        // mock asset cache
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();

        list($private_key, $address) = $this->generateUnmanagedAddress();
        $address_model = $this->addUnamangedAddress($address);

        // create the first transaction
        $parameters = app('SampleSendsHelper')->samplePostVars(['quantity' => 80]);
        $send_details = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters);

        // trying to send again before the first one is completed
        //   should return a 400 error
        $parameters = app('SampleSendsHelper')->samplePostVars(['quantity' => 70]);
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters, 400);
        PHPUnit::assertContains("Unable to compose a new transaction", $result['message']);
    }

    public function testAPIDeleteComposedTransaction() {
        // mock asset cache
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();

        list($private_key, $address) = $this->generateUnmanagedAddress();
        $address_model = $this->addUnamangedAddress($address);

        // create the first transaction
        $parameters = app('SampleSendsHelper')->samplePostVars(['quantity' => 80]);
        $send_details = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters);

        // now revoke it
        $send_details = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('DELETE', '/api/v1/unsigned/sends/'.$send_details['id'], [], 204);

        // creating a new transaction should be just fine now
        $parameters = app('SampleSendsHelper')->samplePostVars(['quantity' => 70]);
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters);
    }


    public function testAPISendComposedTransaction() {
        // mock asset cache
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();

        list($private_key, $address) = $this->generateUnmanagedAddress();
        $address_model = $this->addUnamangedAddress($address);

        // create the first transaction
        $parameters = app('SampleSendsHelper')->samplePostVars(['quantity' => 80]);
        $send_details = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters);

        // sign it
        $transaction_hex = $send_details['unsignedTx'];
        $transaction = TransactionFactory::fromHex($transaction_hex);
        $signer = TransactionFactory::sign($transaction);
        foreach ($transaction->getInputs() as $n => $input) {
            $signer->sign($n, $private_key, $input->getScript());
        }
        PHPUnit::assertTrue($signer->isFullySigned());
        $signed_transaction = $signer->get();

        $signed_txid = $signed_transaction->getTxId()->getHex();
        $signed_tx_hex = $signed_transaction->getBuffer()->getHex();

        // now submit the signed transaction
        $parameters = [
            'signedTx' => $signed_tx_hex,
        ];
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/signed/send/'.$send_details['id'], $parameters);


        // check new txos were made
        $txo_repository = app('App\Repositories\TXORepository');
        $loaded_txos = $txo_repository->findByTXID($signed_txid);
        PHPUnit::assertCount(1, $loaded_txos);
        $loaded_txo = $loaded_txos[0];
        PHPUnit::assertEquals(TXO::UNCONFIRMED, $loaded_txo['type'], 'Unexpected type of '.TXO::typeIntegerToString($loaded_txo['type']));
        PHPUnit::assertFalse($loaded_txo['spent']);
        PHPUnit::assertFalse($loaded_txo['green']);

        // check source TXO marked spent
        $loaded_txos = $txo_repository->findByTXID('1111111111111111111111111111111111111111111111111111111111110001');
        PHPUnit::assertCount(1, $loaded_txos);
        $loaded_txo = $loaded_txos[0];
        PHPUnit::assertEquals(TXO::CONFIRMED, $loaded_txo['type'], 'Unexpected type of '.TXO::typeIntegerToString($loaded_txo['type']));
        PHPUnit::assertTrue($loaded_txo['spent']);

    }

    public function testBitcoinErrorWithAPISendComposedTransaction() {
        // mock asset cache
        // setup bitcoind to return a -25 error
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this, ['sendrawtransaction' => function($hex, $allow_high_fees) {
            throw new \Exception("Test bitcoind error", -25);
        }]);

        $api_tester = $this->getAPITester();

        list($private_key, $address) = $this->generateUnmanagedAddress();
        $address_model = $this->addUnamangedAddress($address);

        // create the first transaction
        $parameters = app('SampleSendsHelper')->samplePostVars(['quantity' => 80]);
        $send_details = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/unsigned/sends/'.$address_model['uuid'], $parameters);

        // sign it
        $transaction_hex = $send_details['unsignedTx'];
        $transaction = TransactionFactory::fromHex($transaction_hex);
        $signer = TransactionFactory::sign($transaction);
        foreach ($transaction->getInputs() as $n => $input) {
            $signer->sign($n, $private_key, $input->getScript());
        }
        PHPUnit::assertTrue($signer->isFullySigned());
        $signed_transaction = $signer->get();

        $signed_txid = $signed_transaction->getTxId()->getHex();
        $signed_tx_hex = $signed_transaction->getBuffer()->getHex();

        // now submit the signed transaction
        //   with a bitcoind failure
        $parameters = [
            'signedTx' => $signed_tx_hex,
        ];
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/signed/send/'.$send_details['id'], $parameters, 500);


        // check the no new txos were made
        $txo_repository = app('App\Repositories\TXORepository');
        $loaded_txos = $txo_repository->findByTXID($signed_txid);
        PHPUnit::assertCount(0, $loaded_txos);

        // check source TXOs are no longer marked spent
        $loaded_txos = $txo_repository->findByTXID('1111111111111111111111111111111111111111111111111111111111110001');
        PHPUnit::assertCount(1, $loaded_txos);
        $loaded_txo = $loaded_txos[0];
        PHPUnit::assertFalse($loaded_txo['spent']);

    }

    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester = app('SimpleAPITester', [$this->app, '/api/v1/unmanaged/addresses', app('App\Repositories\PaymentAddressRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function paymentAddressHelper() {
        if (!isset($this->payment_address_helper)) { $this->payment_address_helper = app('PaymentAddressHelper'); }
        return $this->payment_address_helper;
    }

    protected function generateUnmanagedAddress() {
        $private_key = PrivateKeyFactory::create(true);
        $public_key = $private_key->getPublicKey();
        $address = $public_key->getAddress()->getAddress();

        return [$private_key, $address];
    }

    protected function addUnamangedAddress($address) {
        return $this->paymentAddressHelper()->createSamplePaymentAddress($this->authenticatedUser(), ['address' => $address, 'private_key_token' => '',]);
    }

    protected function authenticatedUser() {
        $user_helper = app('UserHelper');
        $this->authenticated_user = $user_helper->getSampleUser();
        if (!$this->authenticated_user) { 
            $this->authenticated_user = $user_helper->createSampleUser();
        }
        return $this->authenticated_user;
    }

}
