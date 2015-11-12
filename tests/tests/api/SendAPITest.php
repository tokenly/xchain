<?php

use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class SendAPITest extends TestCase {

    protected $useRealSQLiteDatabase = true;

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

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'    => 1,
                    'asset'       => 'TOKENLY',
                    'requestId'   => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                ],
                'expectedErrorString' => 'request id may not be greater than 36 characters.',
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
            'requestId'   => '{{response.requestId}}',
        ];
        $api_response = $api_tester->testAddResource($posted_vars, $expected_created_resource, $payment_address['uuid']);

        // validate that a mock send was triggered
        $transaction_composer_helper = app('TransactionComposerHelper');
        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals($payment_address['address'], $send_details['change'][0][0]);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $send_details['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $send_details['quantity']);
        PHPUnit::assertEquals('TOKENLY', $send_details['asset']);
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
            'requestId'   => '{{response.requestId}}',
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



    public function testAPISendFromAccount()
    {
        // mock the xcp sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

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

    public function testAPISendUnconfirmedFromAccount()
    {
        // mock the xcp sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

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
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'sweep'  => True,
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
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

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
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();

        $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/sends/'.$address['uuid'], $this->sendHelper()->samplePostVars([
            'quantity' => 3,
            'asset'    => 'BTC',
            'account'  => 'accounttwo',
        ]), 400);
    }


    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester =  $this->app->make('SimpleAPITester', [$this->app, '/api/v1/sends', $this->app->make('App\Repositories\SendRepository')]);
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
        $inactive_account = app('AccountHelper')->newSampleAccount($address, ['name' => 'Inactive 1', 'active' => 0]);

        // add balances to each
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $ledger_entry_repo->addCredit(110, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, $txid);
        $ledger_entry_repo->addCredit(100, 'BTC', $created_accounts[1], LedgerEntry::CONFIRMED, $txid);
        $ledger_entry_repo->addDebit(  10, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, $txid);
        $ledger_entry_repo->addCredit(  1, 'BTC', $created_accounts[2], LedgerEntry::CONFIRMED, $txid);
        $ledger_entry_repo->addCredit( 20, 'BTC', $created_accounts[0], LedgerEntry::UNCONFIRMED, $txid);
        $ledger_entry_repo->addCredit( 20, 'BTC', $created_accounts[2], LedgerEntry::UNCONFIRMED, $txid);

        $ledger_entry_repo->addCredit(0.01, 'BTC', $created_accounts[3], LedgerEntry::CONFIRMED, $txid);
        $ledger_entry_repo->addCredit(  20, 'BTC', $created_accounts[3], LedgerEntry::UNCONFIRMED, $txid);

        // add UTXOs
        $float_btc_balance = 110 + 100 - 10 + 20 + 20 + 0.01 + 20;
        app('PaymentAddressHelper')->addUTXOToPaymentAddress($float_btc_balance, $address);

        $api_test_helper = app('APITestHelper')->useUserHelper(app('UserHelper'));

        return [$address, $created_accounts, $api_test_helper];
    }

}
