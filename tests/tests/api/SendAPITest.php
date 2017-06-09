<?php

use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class SendAPITest extends TestCase {

    protected $useRealSQLiteDatabase = true;

    public function testRequireAuthForSends() {
        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth('POST', $payment_address['uuid']);
    }

    public function testAPIErrorsSend()
    {
        // mock the xcp sender
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);
        app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

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

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'    => 1,
                    'asset'       => 'TOKENLY',
                    'requestId'   => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                ],
                'expectedErrorString' => 'request id may not be greater than 36 characters.',
            ],

            [
                'postVars' => [
                    'destination'   => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'      => 1,
                    'asset'         => 'TOKENLY',
                    'utxo_override' => 'foo',
                    'feeRate'       => 80,
                ],
                'expectedErrorString' => 'You cannot specify a fee rate with utxo_override.',
            ],

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'    => 1,
                    'asset'       => 'TOKENLY',
                    'feeRate'     => 80,
                    'fee'         => 0.0001,
                ],
                'expectedErrorString' => 'You cannot specify a fee rate and a fee.',
            ],

        ], '/'.$payment_address['uuid']);
    }

    public function testAPIErrorsForMultisend()
    {
        // mock the xcp sender
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester('/api/v1/multisends');

        $api_tester->testAddErrors([

            [
                'postVars' => [
                    'fee'    => 0.0001,
                ],
                'expectedErrorString' => 'destinations field is required.',
            ],
            [
                'postVars' => [
                    'destinations'    => 'bad',
                ],
                'expectedErrorString' => 'destinations were invalid',
            ],
            [
                'postVars' => [
                    'destinations'    => [['address' => 'badaddress1', 'amount' => 100]],
                ],
                'expectedErrorString' => 'address for destination 1 was invalid',
            ],
            [
                'postVars' => [
                    'destinations'    => [['address' => '1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', 'amount' => 0]],
                ],
                'expectedErrorString' => 'amount for destination 1 was invalid',
            ],
            [
                'postVars' => [
                    'destinations'    => [['address' => '1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', 'amount' => -0.2]],
                ],
                'expectedErrorString' => 'amount for destination 1 was invalid',
            ],
            [
                'postVars' => [
                    'destinations'    => [['address' => '1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', 'amount' => 'blahblahblah']],
                ],
                'expectedErrorString' => 'amount for destination 1 was invalid',
            ],
            [
                'postVars' => [
                    'destinations'    => [['address' => '1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB',]],
                ],
                'expectedErrorString' => 'address or amount for destination',
            ],
            [
                'postVars' => [
                    'destinations'    => [['amount' => 1,]],
                ],
                'expectedErrorString' => 'address or amount for destination',
            ],

        ], '/'.$payment_address['uuid']);
    }

    public function testAPIAddSend()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars();
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '{{response.destination}}',
            'destinations' => '',
            'asset'        => 'TOKENLY',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => '{{response.quantity}}',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate that a mock send was triggered
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0], CurrencyUtil::valueToSatoshis(1));
        PHPUnit::assertEquals($payment_address['address'], $send_details['change'][0][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $send_details['quantity']);
        PHPUnit::assertEquals('TOKENLY', $send_details['asset']);
        PHPUnit::assertEquals(10000, $send_details['fee']);
    }

    public function testFeePerByteSendAPI()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars(['feeRate' => '75']);
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '{{response.destination}}',
            'destinations' => '',
            'asset'        => 'TOKENLY',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => '{{response.quantity}}',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
            'feePerByte'   => '75',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate that a mock send was triggered
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0], CurrencyUtil::valueToSatoshis(1));
        PHPUnit::assertEquals($payment_address['address'], $send_details['change'][0][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $send_details['quantity']);
        PHPUnit::assertEquals('TOKENLY', $send_details['asset']);
        PHPUnit::assertEquals(19800, $send_details['fee']); // 264 * 75
        PHPUnit::assertEquals(75, $send_details['fee_per_byte']);
    }

    public function testAPISweep()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars();
        unset($posted_vars['quantity']);
        $posted_vars['sweep'] = true;
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '{{response.destination}}',
            'destinations' => '',
            'asset'        => 'TOKENLY',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => '{{response.quantity}}',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate the sweep
        PHPUnit::assertCount(2, $mock_calls['btcd']);
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals('TOKENLY', $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.00005430), $send_details['btc_amount']);

        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][1]['args'][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(1 - 0.0001 - 0.0001 - 0.00005430), $send_details['btc_amount']);
    }

    public function testFeePerByteAPISweep()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars(['feeRate' => '75',]);
        unset($posted_vars['quantity']);
        $posted_vars['sweep'] = true;
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '{{response.destination}}',
            'destinations' => '',
            'asset'        => 'TOKENLY',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => '{{response.quantity}}',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
            'feePerByte'   => '75',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate the sweep
        PHPUnit::assertCount(2, $mock_calls['btcd']);
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0], CurrencyUtil::valueToSatoshis(1));
        // echo "ASSET \$send_details: ".json_encode($send_details, 192)."\n";
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals('TOKENLY', $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.00005430), $send_details['btc_amount']);
        $expected_asset_fee_sat = 19800; // 264 * 75
        PHPUnit::assertEquals(75, $send_details['fee_per_byte']);
        PHPUnit::assertEquals($expected_asset_fee_sat, $send_details['fee']);

        $remaining_btc = CurrencyUtil::valueToSatoshis(1) - $expected_asset_fee_sat - 5430;
        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][1]['args'][0], $remaining_btc);
        // echo "BTC \$send_details: ".json_encode($send_details, 192)."\n";
        $expected_btc_fee_sat = 14325;
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(1) - 5430 - $expected_asset_fee_sat - $expected_btc_fee_sat, $send_details['btc_amount']);
    }

    public function testSecondAPISweep()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user, [], ['BTC' => 0.00030860, 'LTBCOIN' => 75, 'TOKENLY' => 0,]);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars(['sweep' => true, 'asset' => 'ALLASSETS',]);
        unset($posted_vars['quantity']);
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '{{response.destination}}',
            'destinations' => '',
            'asset'        => 'ALLASSETS',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => '{{response.quantity}}',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate the sweep
        PHPUnit::assertCount(2, $mock_calls['btcd']);
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals('LTBCOIN', $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.00005430), $send_details['btc_amount']);

        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][1]['args'][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.00030860 - 0.0001 - 0.0001 - 0.00005430), $send_details['btc_amount']);
    }


    public function testSweepUnconfirmedFunds()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user, [], ['BTC' => 0.00030860, 'LTBCOIN' => 75, 'TOKENLY' => 0,]);
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['BTC' => 6], $payment_address, true, 'default', 'SAMPLE09', LedgerEntry::UNCONFIRMED);

        $api_tester = $this->getAPITester();

        $posted_vars = $this->sendHelper()->samplePostVars(['sweep' => true, 'asset' => 'ALLASSETS',]);
        unset($posted_vars['quantity']);
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '{{response.destination}}',
            'destinations' => '',
            'asset'        => 'ALLASSETS',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => '{{response.quantity}}',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate the sweep
        PHPUnit::assertCount(2, $mock_calls['btcd']);
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals('LTBCOIN', $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.00005430), $send_details['btc_amount']);

        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][1]['args'][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.00030860 - 0.0001 - 0.0001 - 0.00005430 + 6), $send_details['btc_amount']);
    }



    public function testAPISendFromAccount()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity' => 0.05,
            'asset'    => 'BTC',
            'account'  => 'accountone',
        ]));

        // accountone should now have funds moved into sent
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');

        $default_account_balances = $ledger_entry_repo->accountBalancesByAsset($created_accounts[0], null);
        $account_one_balances = $ledger_entry_repo->accountBalancesByAsset($created_accounts[1], null);
        PHPUnit::assertEquals([
            'unconfirmed' => [],
            'confirmed'   => ['BTC' => 99.9499,],
            'sending'     => ['BTC' =>  0.0501,],
        ], $account_one_balances);


    }
    
    public function testAPISendWithSpecificUTXOs()
    {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        
        list($address, $created_accounts, $api_test_helper, $utxos) = $this->setupBalancesForTransfer();
        
        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity' => 1,
            'asset'    => 'LTBCOIN',
            'account'  => 'accountfour',
            'utxo_override' => $utxos,
        ]));        
       
        // accountfour should now have funds moved into sent
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');

        $account_four_balances = $ledger_entry_repo->accountBalancesByAsset($created_accounts[4], null);
        PHPUnit::assertEquals([
            'unconfirmed' => [],
            'confirmed'   => ['LTBCOIN' => 9999, 'BTC' => 0.99984570],
            'sending'     => ['LTBCOIN' =>  1, 'BTC' => 0.00015430],
        ], $account_four_balances);        
    }

    public function testAPISendUnconfirmedFromAccount()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        // without unconfirmed fails
        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity'    => 0.05,
            'asset'       => 'BTC',
            'account'     => 'accountthree',
        ]), 400);

        // with unconfirm succeeds
        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity'    => 0.05,
            'asset'       => 'BTC',
            'account'     => 'accountthree',
            'unconfirmed' => true,
        ]));

        // accountone should now have funds moved into sent
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');

        $account_three_balances = $ledger_entry_repo->accountBalancesByAsset($created_accounts[3], null);
        PHPUnit::assertEquals([
            'unconfirmed' => ['BTC' => 19.9599,],
            'confirmed'   => ['BTC' =>  0.0,],
            'sending'     => ['BTC' =>  0.0501,],
        ], $account_three_balances);


    }


    public function testSweepFromAccount()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // account1 => [
        //     unconfirmed => [BTC => 20]
        //     confirmed   => [BTC => 100]
        // ]
        // account2 => [
        //     confirmed   => [BTC => 100]
        // ]
        // account3 => [
        //     unconfirmed => [BTC => 20]
        // ]
        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'sweep'  => true,
        ]));

        // accountone should now have funds moved into sent
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');

        $default_account_balances = $ledger_entry_repo->accountBalancesByAsset($created_accounts[0], null);
        $account_one_balances = $ledger_entry_repo->accountBalancesByAsset($created_accounts[1], null);
        PHPUnit::assertEquals([
            'unconfirmed' => [],
            'confirmed'   => ['BTC' => 0,],
            'sending'     => [],
        ], $account_one_balances);


    }

    // test send with invalid account name returns 404
    public function testAPISendFromBadAccount()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity' => 0.05,
            'asset'    => 'BTC',
            'account'  => 'accountDOESNOTEXIST',
        ]), 404);
    }


    // test insufficient funds in account
    public function testAPISendWithInsufficientFunds()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity' => 3,
            'asset'    => 'BTC',
            'account'  => 'accounttwo',
        ]), 400);
    }



    public function testAPIAddMultisend()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester('/api/v1/multisends');

        $posted_vars = $this->sendHelper()->sampleMultisendPostVars();
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '',
            'destinations' => '{{response.destinations}}',
            'asset'        => 'BTC',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => 0.006,
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate the send details
        $transaction_composer_helper = app('TransactionComposerHelper');

        PHPUnit::assertCount(1, $mock_calls['btcd']);
        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][0]['args'][0]);

        // primary send
        PHPUnit::assertEquals('1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.001), $send_details['btc_amount']);

        // other change...
        PHPUnit::assertEquals('1ATEST222XXXXXXXXXXXXXXXXXXXYzLVeV', $send_details['change'][0][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.002), $send_details['change'][0][1]);
        PHPUnit::assertEquals('1ATEST333XXXXXXXXXXXXXXXXXXXatH8WE', $send_details['change'][1][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.003), $send_details['change'][1][1]);
        PHPUnit::assertEquals($payment_address['address'], $send_details['change'][2][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(1 - 0.006 - 0.0001), $send_details['change'][2][1]);
    }

    public function testFeePerByteAPIAddMultisend()
    {
        // mock the xcp sender
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = app('\UserHelper')->createSampleUser();
        $payment_address = app('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester('/api/v1/multisends');

        $posted_vars = $this->sendHelper()->sampleMultisendPostVars(['feeRate' => '75']);
        $expected_created_resource = [
            'id'           => '{{response.id}}',
            'destination'  => '',
            'destinations' => '{{response.destinations}}',
            'asset'        => 'BTC',
            'txid'         => '{{response.txid}}',
            'requestId'    => '{{response.requestId}}',
            'sweep'        => '{{response.sweep}}',
            'quantity'     => 0.006,
            'feePerByte'   => '75',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate the send details
        $transaction_composer_helper = app('TransactionComposerHelper');

        PHPUnit::assertCount(1, $mock_calls['btcd']);
        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][0]['args'][0], CurrencyUtil::valueToSatoshis(1));
        // echo "\$send_details: ".json_encode($send_details, 192)."\n";

        // primary send
        PHPUnit::assertEquals('1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.001), $send_details['btc_amount']);

        // other change...
        $expected_fee_sat = 21975;
        PHPUnit::assertEquals('1ATEST222XXXXXXXXXXXXXXXXXXXYzLVeV', $send_details['change'][0][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.002), $send_details['change'][0][1]);
        PHPUnit::assertEquals('1ATEST333XXXXXXXXXXXXXXXXXXXatH8WE', $send_details['change'][1][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.003), $send_details['change'][1][1]);
        PHPUnit::assertEquals($payment_address['address'], $send_details['change'][2][0]);
        PHPUnit::assertEquals($expected_fee_sat, $send_details['fee']);
        PHPUnit::assertEquals(75, $send_details['fee_per_byte']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(1 - 0.006 - CurrencyUtil::satoshisToValue($expected_fee_sat)), $send_details['change'][2][1]);

    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester($url='/api/v1/sends') {
        $api_tester =  app('SimpleAPITester', [$this->app, $url, app('App\Repositories\SendRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }



    protected function sendHelper() {
        if (!isset($this->sample_sends_helper)) { $this->sample_sends_helper = app('SampleSendsHelper'); }
        return $this->sample_sends_helper;
    }

    protected function monitoredAddressByAddress() {
        $address_repo = app('App\Repositories\MonitoredAddressRepository');
        $payment_address = $address_repo->findByAddress('RECIPIENT01')->first();
        return $payment_address;

    }

    protected function newSampleAccount(PaymentAddress $address, $account_vars_or_name=null) {
        static $address_name_counter = 0;
        if ($account_vars_or_name AND !is_array($account_vars_or_name)) { $account_vars = ['name' => $account_vars_or_name]; }
            else if ($account_vars_or_name === null) { $account_vars = []; }
            else { $account_vars = $account_vars_or_name; }

        if (!isset($account_vars['name'])) {
            $account_vars['name'] = "Address ".(++$address_name_counter);
        }

        return app('AccountHelper')->newSampleAccount($address, $account_vars);
    }



    // account1 => [
    //     unconfirmed => [BTC => 20]
    //     confirmed   => [BTC => 100]
    // ]
    // account2 => [
    //     confirmed   => [BTC => 100]
    // ]
    // account3 => [
    //     unconfirmed => [BTC => 20]
    // ]
    protected function setupBalancesForTransfer() {
        // setup balances
        $sample_user = app('UserHelper')->createSampleUser();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount($sample_user);

        // create models
        $created_accounts = [];
        $created_accounts[] = $this->newSampleAccount($address, 'default');
        $created_accounts[] = $this->newSampleAccount($address, 'accountone');
        $created_accounts[] = $this->newSampleAccount($address, 'accounttwo');
        $created_accounts[] = $this->newSampleAccount($address, 'accountthree');
        $created_accounts[] = $this->newSampleAccount($address, 'accountfour');
        $inactive_account = app('AccountHelper')->newSampleAccount($address, ['name' => 'Inactive 1', 'active' => 0]);

        // add balances to each
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $ledger_entry_repo->addCredit(110, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $ledger_entry_repo->addCredit(100, 'BTC', $created_accounts[1], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $ledger_entry_repo->addDebit(  10, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $ledger_entry_repo->addCredit(  1, 'BTC', $created_accounts[2], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $ledger_entry_repo->addCredit( 20, 'BTC', $created_accounts[0], LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $ledger_entry_repo->addCredit( 20, 'BTC', $created_accounts[2], LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        $ledger_entry_repo->addCredit(0.01, 'BTC', $created_accounts[3], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $ledger_entry_repo->addCredit(  20, 'BTC', $created_accounts[3], LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        // add UTXOs
        $float_btc_balance = 110 + 100 - 10 + 20 + 20 + 0.01 + 20;
        app('PaymentAddressHelper')->addUTXOToPaymentAddress($float_btc_balance, $address);
        
        //setup a new account and few more utxos for utxo_override testing
        $txo_helper = app('SampleTXOHelper');
        $sample_tx = $txo_helper->nextTXID();
        $ledger_entry_repo->addCredit(1, 'BTC', $created_accounts[4], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $sample_tx);
        $ledger_entry_repo->addCredit(10000, 'LTBCOIN', $created_accounts[4], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $sample_tx);
        $extra_utxos = array();
        $extra_utxos[] = $txo_helper->createSampleTXO($address, ['txid' => $txid, 'amount' => 100000000,  'n' => 0]);
        

        $api_test_helper = app('APITestHelper')->useUserHelper(app('UserHelper'));

        return [$address, $created_accounts, $api_test_helper, $extra_utxos];
    }

}
