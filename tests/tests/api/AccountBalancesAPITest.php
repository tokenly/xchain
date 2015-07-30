<?php

use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class AccountBalancesAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIGetBalances()
    {
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount($sample_user);

        // add noise
        $sample_user_2 = app('UserHelper')->newRandomUser();
        $address_2 = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount($sample_user_2);
        app('AccountHelper')->newSampleAccount($address_2);
        app('AccountHelper')->newSampleAccount($address_2, 'address 2');

        // create 2 models
        $api_test_helper = $this->getAPITestHelper($address);
        $created_accounts = [];
        $created_accounts[] = $api_test_helper->newModel();
        $created_accounts[] = $api_test_helper->newModel();
        $inactive_account = app('AccountHelper')->newSampleAccount($address, ['name' => 'Inactive 1', 'active' => 0]);

        // add balances to each
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(11, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(20, 'BTC', $created_accounts[1], LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(  1, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 4, 'BTC', $created_accounts[0], LedgerEntry::UNCONFIRMED, $txid);
        $repo->addCredit( 5, 'BTC', $created_accounts[1], LedgerEntry::UNCONFIRMED, $txid);

        // now get all the accounts
        $api_response = $api_test_helper->callAPIAndValidateResponse('GET', '/api/v1/accounts/balances/'.$address['uuid'].'');
        PHPUnit::assertCount(2, $api_response);
        PHPUnit::assertEquals($created_accounts[0]['uuid'], $api_response[0]['id']);

        // check balances
        PHPUnit::assertEquals(['BTC' => 10], $api_response[0]['balances']['confirmed']);
        PHPUnit::assertEquals(['BTC' => 20], $api_response[1]['balances']['confirmed']);
        PHPUnit::assertEquals(['BTC' => 4 ], $api_response[0]['balances']['unconfirmed']);
        PHPUnit::assertEquals(['BTC' => 5 ], $api_response[1]['balances']['unconfirmed']);

        // get by name
        $api_response = $api_test_helper->callAPIAndValidateResponse('GET', '/api/v1/accounts/balances/'.$address['uuid'].'?name=Address 1');
        PHPUnit::assertCount(1, $api_response);
        PHPUnit::assertEquals($created_accounts[0]['uuid'], $api_response[0]['id']);
        PHPUnit::assertEquals(['BTC' => 10], $api_response[0]['balances']['confirmed']);


        // get inactive
        $api_response = $api_test_helper->callAPIAndValidateResponse('GET', '/api/v1/accounts/balances/'.$address['uuid'].'?active=false');
        PHPUnit::assertCount(1, $api_response);
        PHPUnit::assertEquals($inactive_account['uuid'], $api_response[0]['id']);


        // get by type
        $api_response = $api_test_helper->callAPIAndValidateResponse('GET', '/api/v1/accounts/balances/'.$address['uuid'].'?type=unconfirmed');
        PHPUnit::assertCount(2, $api_response);
        PHPUnit::assertEquals(['BTC' => 4 ], $api_response[0]['balances']);
        PHPUnit::assertEquals(['BTC' => 5 ], $api_response[1]['balances']);

        $api_response = $api_test_helper->callAPIAndValidateResponse('GET', '/api/v1/accounts/balances/'.$address['uuid'].'?type=confirmed');
        PHPUnit::assertCount(2, $api_response);
        PHPUnit::assertEquals(['BTC' => 10 ], $api_response[0]['balances']);
        PHPUnit::assertEquals(['BTC' => 20 ], $api_response[1]['balances']);
    }


    public function testAPITransferBalances()
    {
        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();
        list($account_1, $account_2, $account_3) = $created_accounts;

        // transfer 
        $api_response = $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/accounts/transfer/'.$address['uuid'], [
            'from'     => $account_1['name'],
            'to'       => $account_2['name'],
            'quantity' => 15,
            'asset'    => 'BTC',
            // 'type'     => 'confirmed',
        ], 204);

        $repo = app('App\Repositories\LedgerEntryRepository');
        $account_1_balances = $repo->accountBalancesByAsset($account_1, LedgerEntry::CONFIRMED);
        $account_2_balances = $repo->accountBalancesByAsset($account_2, LedgerEntry::CONFIRMED);

        // check balances
        PHPUnit::assertEquals(['BTC' => 85],  $account_1_balances);
        PHPUnit::assertEquals(['BTC' => 115], $account_2_balances);

    }

    public function testAPITransferAllUnconfirmedTransaction()
    {
        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();
        list($account_1, $account_2, $account_3) = $created_accounts;

        // transfer 
        $api_response = $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/accounts/transfer/'.$address['uuid'], [
            'from'     => $account_1['name'],
            'to'       => 'another-account',
            'txid'     => 'deadbeef00000000000000000000000000000000000000000000000000000002',
        ], 204);

        $repo = app('App\Repositories\LedgerEntryRepository');
        $account_repository = app('App\Repositories\AccountRepository');
        $temp_account = $account_repository->findByName('another-account', $address['id']);
        $account_1_unconfirmed_balances = $repo->accountBalancesByAsset($account_1, LedgerEntry::UNCONFIRMED);
        $temp_account_balances = $repo->accountBalancesByAsset($temp_account, LedgerEntry::UNCONFIRMED);

        // check balances
        PHPUnit::assertEquals(['BTC' => 5], $temp_account_balances);
        PHPUnit::assertEquals(['BTC' => 15],  $account_1_unconfirmed_balances);

    }

    public function testAPICloseAccount() {
        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();
        list($account_1, $account_2, $account_3) = $created_accounts;

        // close 
        $api_response = $api_test_helper->callAPIAndValidateResponse('POST', '/api/v1/accounts/transfer/'.$address['uuid'], [
            'from'     => $account_1['name'],
            'to'       => $account_3['name'],
            'close'    => true,
        ], 204);

        $repo = app('App\Repositories\LedgerEntryRepository');
        $account_1_balances = $repo->accountBalancesByAsset($account_1, null);
        $account_3_balances = $repo->accountBalancesByAsset($account_3, null);

        // check balances
        $default_balances = ['unconfirmed' => ['BTC' => 0], 'confirmed' => ['BTC' => 0], 'sending' => []];
        PHPUnit::assertEquals(array_merge($default_balances, []),  $account_1_balances);
        PHPUnit::assertEquals(array_merge($default_balances, [
            'unconfirmed' => ['BTC' => 40],
            'confirmed'   => ['BTC' => 100],
        ]),  $account_3_balances);
    }

    public function testErrorsAPITransferAccount()
    {
        list($address, $created_accounts, $api_test_helper) = $this->setupBalancesForTransfer();
        list($account_1, $account_2, $account_3) = $created_accounts;

        $test_default_params = [
            'method'            => 'POST',
            'urlPath'           => '/transfer/'.$address['uuid'],
            'expectedErrorCode' => 400,
        ];
        $default_vars = [
            'from'     => $account_1['name'],
            'to'       => $account_2['name'],
            'quantity' => 15,
            'asset'    => 'BTC',
            'type'     => 'confirmed',
        ];
        $api_test_helper->testErrors([
            [
                'postVars' => array_merge($default_vars, [
                    'from'     => 'doesnotexist',
                ]),
                'expectedErrorString' => 'unable to find `from` account',
                'expectedErrorCode'   => 404,
            ],
            [
                'postVars' => array_merge($default_vars, [
                    'asset'     => '',
                ]),
                'expectedErrorString' => 'asset field is required.',
                'expectedErrorCode'   => 400,
            ],
            [
                'postVars' => array_merge($default_vars, [
                    'quantity'     => '',
                ]),
                'expectedErrorString' => 'quantity field is required',
                'expectedErrorCode'   => 400,
            ],
            [
                'postVars' => array_merge($default_vars, [
                    'quantity'     => '0',
                ]),
                'expectedErrorString' => 'quantity is invalid.',
                'expectedErrorCode'   => 400,
            ],
            [
                'postVars' => array_merge($default_vars, [
                    'close'     => true,
                ]),
                'expectedErrorString' => 'asset field is not allowed',
                'expectedErrorCode'   => 400,
            ],
            [
                'postVars' => array_merge($default_vars, [
                    'close'     => true,
                    'asset'     => null,
                ]),
                'expectedErrorString' => 'quantity field is not allowed',
                'expectedErrorCode'   => 400,
            ],
            [
                'postVars' => array_merge($default_vars, [
                    'quantity' => 15,
                    'asset'    => 'BTC',
                    'txid'     => 'deadbeef00000000000000000000000000000000000000000000000000000002'
                ]),
                'expectedErrorString' => 'account does not have sufficient funds',
                'expectedErrorCode'   => 400,
            ],
        ], $test_default_params);
    }

    ////////////////////////////////////////////////////////////////////////

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
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount($sample_user);

        // add noise
        $sample_user_2 = app('UserHelper')->newRandomUser();
        $address_2 = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount($sample_user_2);
        app('AccountHelper')->newSampleAccount($address_2);
        app('AccountHelper')->newSampleAccount($address_2, 'address 2');

        // create 2 models
        $api_test_helper = $this->getAPITestHelper($address);
        $created_accounts = [];
        $created_accounts[] = $api_test_helper->newModel();
        $created_accounts[] = $api_test_helper->newModel();
        $created_accounts[] = $api_test_helper->newModel();
        $inactive_account = app('AccountHelper')->newSampleAccount($address, ['name' => 'Inactive 1', 'active' => 0]);

        // add balances to each
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';
        $txid2 = 'deadbeef00000000000000000000000000000000000000000000000000000002';
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(110, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(100, 'BTC', $created_accounts[1], LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(  10, 'BTC', $created_accounts[0], LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 15, 'BTC', $created_accounts[0], LedgerEntry::UNCONFIRMED, $txid);
        $repo->addCredit(  5, 'BTC', $created_accounts[0], LedgerEntry::UNCONFIRMED, $txid2);
        $repo->addCredit( 20, 'BTC', $created_accounts[2], LedgerEntry::UNCONFIRMED, $txid);

        return [$address, $created_accounts, $api_test_helper];
    }
    
    protected function getAPITestHelper(PaymentAddress $address=null) {
        $address_name_counter = 0;

        $tester = app('APITestHelper');
        $tester
            ->setURLBase('/api/v1/accounts')
            ->useRepository(app('App\Repositories\AccountRepository'))
            ->useUserHelper(app('UserHelper'))
            ->createModelWith(function() use ($address, &$address_name_counter) {
                return app('AccountHelper')->newSampleAccount($address, "Address ".(++$address_name_counter));
            })
            ;

        return $tester;
    }


}
