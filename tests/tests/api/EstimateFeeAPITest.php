<?php

use App\Blockchain\Sender\PaymentAddressSender;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Providers\Accounts\Facade\AccountHandler;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class EstimateFeeAPITest extends TestCase {

    protected $useRealSQLiteDatabase = true;


    public function testAPIErrorsEstimateFee()
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
                    'asset'       => 'FOO',
                    'quantity'    => 0,
                ],
                'expectedErrorString' => 'quantity is invalid',
            ],

        ], '/'.$payment_address['uuid']);
    }

    public function testAPICalculateFee()
    {
        // mock the sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        // BTC send
        $posted_vars = $this->sendHelper()->samplePostVars();
        $posted_vars['quantity'] = 0.025;
        $posted_vars['asset'] = 'BTC';

        $response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/estimatefee/'.$payment_address['uuid'], $posted_vars);

        $bytes = 225;
        PHPUnit::assertEquals([
            'size' => $bytes,
            'fees' => [
                'low'     => CurrencyUtil::satoshisToValue(PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_LOW * $bytes),
                'lowSat'  => PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_LOW * $bytes,
                'med'     => CurrencyUtil::satoshisToValue(PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_MED * $bytes),
                'medSat'  => PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_MED * $bytes,
                'high'    => CurrencyUtil::satoshisToValue(PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes),
                'highSat' => PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes,
            ],
        ], $response);

        // Counterparty send
        $posted_vars = $this->sendHelper()->samplePostVars();
        $posted_vars['quantity'] = 10;
        $posted_vars['asset'] = 'TOKENLY';

        $response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/estimatefee/'.$payment_address['uuid'], $posted_vars);
        $bytes = 264;
        PHPUnit::assertEquals([
            'size' => $bytes,
            'fees' => [
                'low'     => CurrencyUtil::satoshisToValue(PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_LOW * $bytes),
                'lowSat'  => PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_LOW * $bytes,
                'med'     => CurrencyUtil::satoshisToValue(PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_MED * $bytes),
                'medSat'  => PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_MED * $bytes,
                'high'    => CurrencyUtil::satoshisToValue(PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes),
                'highSat' => PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes,
            ],
        ], $response);
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester($url='/api/v1/estimatefee') {
        $api_tester =  $this->app->make('SimpleAPITester', [$this->app, $url, $this->app->make('App\Repositories\SendRepository')]);
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

        $api_test_helper = app('APITestHelper')->useUserHelper(app('UserHelper'));

        return [$address, $created_accounts, $api_test_helper];
    }

}
