<?php

use App\Models\PaymentAddress;
use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class AccountsAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIAddAccount()
    {
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();

        $api_test_helper = $this->getAPITestHelper();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);

        $posted_vars = app('AccountHelper')->sampleVarsForAPI();
        $posted_vars['addressId'] = $address['uuid'];

        $created_account_model = $api_test_helper->testCreate($posted_vars);
        $loaded_account_model = app('App\Repositories\AccountRepository')->findByUuid($created_account_model['uuid']);
        PHPUnit::assertEquals($created_account_model['name'], $loaded_account_model['name']);
    }

    public function testErrorsAPIAddAccount()
    {
        $sample_user = app('UserHelper')->createSampleUser();
        $api_test_helper = $this->getAPITestHelper();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);

        $sample_vars = app('AccountHelper')->sampleVarsForAPI();
        $sample_vars['addressId'] = $address['uuid'];

        $api_test_helper->testAddErrors([
            [
                'postVars' => array_merge($sample_vars, [
                    'name'   => '',
                ]),
                'expectedErrorString' => 'name field is required',
            ],
        ]);
    }


    public function testAPIUpdateAccount()
    {
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();

        $api_test_helper = $this->getAPITestHelper();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);

        $api_test_helper->testUpdate(['name' => 'updated account name here']);
    }

    public function testErrorsAPIUpdateAccount()
    {
        $sample_user = app('UserHelper')->createSampleUser();
        $api_test_helper = $this->getAPITestHelper();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);
        $created_account = app('AccountHelper')->newSampleAccount($address);

        $api_test_helper->testUpdateErrors($created_account, [
            [
                'postVars' => array_merge([], [
                    'name'     => str_repeat('x', 129),
                ]),
                'expectedErrorString' => 'name may not be greater than 127 characters',
            ],
        ]);
    }

    public function testErrorsAPIUpdateDefaultAccount()
    {
        $sample_user = app('UserHelper')->createSampleUser();
        $api_test_helper = $this->getAPITestHelper();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount($sample_user);
        $account = app('AccountHelper')->newSampleAccount($address, 'default');
        $api_test_helper->callAPIAndValidateResponse('POST', "/api/v1/accounts/{$account['uuid']}", ['name' => 'blah'], 400);
    }

    public function testAccountAPIRequiresUser()
    {
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();

        $api_test_helper = $this->getAPITestHelper();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);

        $account = app('AccountHelper')->newSampleAccount($address);

        $api_test_helper->testURLCallRequiresUser("/api/v1/accounts/{$address['uuid']}");
    }


    public function testAPIListAccounts() {
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);

        $api_test_helper = $this->getAPITestHelper($address);

        $api_test_helper->testIndex($address['uuid'], true, function() {
            // add noise
            $sample_user_2 = app('UserHelper')->newRandomUser();
            $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user_2);
            app('AccountHelper')->newSampleAccount($address);
            app('AccountHelper')->newSampleAccount($address, 'address 2');
        });
    }

    public function testAccountAPIGetRequiresUser() {
        $sample_user = app('UserHelper')->createSampleUser();

        $api_test_helper = $this->getAPITestHelper();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);
        $account = app('AccountHelper')->newSampleAccount($address);

        $api_test_helper->testURLCallRequiresUser("/api/v1/accounts/{$account['uuid']}");
    }

    public function testAPIGetAccountByID() {
        $sample_user = app('UserHelper')->createSampleUser();
        $api_test_helper = $this->getAPITestHelper();
        $api_test_helper->setURLBase('/api/v1/account');
        $api_test_helper->testShow();
    }

    public function testAPIRequireUserForGetAccount() {
        $api_test_helper = $this->getAPITestHelper();

        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();
        $sample_user_2 = app('UserHelper')->newRandomUser();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);
        $address_2 = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user_2);

        $account = app('AccountHelper')->newSampleAccount($address);
        $account_2 = app('AccountHelper')->newSampleAccount($address_2, 'Unauthorized Account Here');

        // should not be able to load account_2
        $response = $api_test_helper->callAPIAndValidateResponse('GET', "/api/v1/accounts/{$address_2['uuid']}", [], 403);
    }


    public function testAPIGetAccountByName() {
        $api_test_helper = $this->getAPITestHelper();

        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();
        $sample_user_2 = app('UserHelper')->newRandomUser();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);
        $address_2 = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user_2);

        $account = app('AccountHelper')->newSampleAccount($address, 'accountnameone');
        $account_1b = app('AccountHelper')->newSampleAccount($address, 'accountnametwo');
        $account_2 = app('AccountHelper')->newSampleAccount($address_2, 'accountnameone');

        // should not be able to load address_2
        $response = $api_test_helper->callAPIAndValidateResponse('GET', "/api/v1/accounts/{$address_2['uuid']}?name=accountnameone", [], 403);

        // should load for address
        $response = $api_test_helper->callAPIAndValidateResponse('GET', "/api/v1/accounts/{$address['uuid']}?name=accountnameone");
        PHPUnit::assertCount(1, $response);
        PHPUnit::assertEquals($account['uuid'], $response[0]['id']);
    }


    public function testAPIMakeInactiveTransfersaBalancesToDefaultAccount()
    {
        // sample user for Auth
        $sample_user = app('UserHelper')->createSampleUser();

        $api_test_helper = $this->getAPITestHelper();
        $address = app('PaymentAddressHelper')->createSamplePaymentAddress($sample_user);

        $api_test_helper->testUpdate(['name' => 'updated account name here']);
    }


    ////////////////////////////////////////////////////////////////////////
    
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
