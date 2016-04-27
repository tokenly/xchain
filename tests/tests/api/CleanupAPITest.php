<?php

use App\Blockchain\Sender\PaymentAddressSender;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Providers\Accounts\Facade\AccountHandler;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class CleanupAPITest extends TestCase {

    protected $useRealSQLiteDatabase = true;


    public function testAPIErrorsCleanup()
    {
        // mock the xcp sender
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $api_tester->testAddErrors([

            [
                'postVars' => [
                ],
                'expectedErrorString' => 'max utxos field is required',
            ],

            [
                'postVars' => [
                    'max_utxos' => 0,
                ],
                'expectedErrorString' => 'max utxos must be at least 1',
            ],

            [
                'postVars' => [
                    'max_utxos' => 5,
                    'priority' => 'unknown',
                ],
                'expectedErrorString' => 'The priority must be a valid fee priority like low, medium, high',
            ],

        ], '/'.$payment_address['uuid']);
    }

    public function testAPICleanup()
    {
        // mock the sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        for ($i=0; $i < 10; $i++) { 
            app('PaymentAddressHelper')->addUTXOToPaymentAddress(0.0001, $payment_address);
        }

        $api_tester = $this->getAPITester();

        // BTC send
        $posted_vars = [
            'max_utxos' => 5,
        ];

        $cleanup_result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/cleanup/'.$payment_address['uuid'], $posted_vars);
        PHPUnit::assertEquals(5, $cleanup_result['after_utxos_count']);
        PHPUnit::assertTrue($cleanup_result['cleaned_up']);
        PHPUnit::assertNotEmpty($cleanup_result['txid']);

        $send_raw_transaction_call = $mock_calls['btcd'][0];
        PHPUnit::assertEquals('sendrawtransaction', $send_raw_transaction_call['method']);
        $transaction_data = app('TransactionComposerHelper')->parseBTCTransaction($send_raw_transaction_call['args'][0]);
        PHPUnit::assertEquals(7, count($transaction_data['inputs']));
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester($url='/api/v1/cleanup') {
        $api_tester =  $this->app->make('SimpleAPITester', [$this->app, $url, $this->app->make('App\Repositories\SendRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }



    protected function sendHelper() {
        if (!isset($this->sample_sends_helper)) { $this->sample_sends_helper = $this->app->make('SampleSendsHelper'); }
        return $this->sample_sends_helper;
    }





}
