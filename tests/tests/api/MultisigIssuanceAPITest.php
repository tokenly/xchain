<?php

use App\Models\TXO;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use Illuminate\Support\Facades\Queue;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class MultisigIssuanceAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIErrorsAddMultisigIssuance()
    {
        // create a multisig payment address
        $payment_address = $this->paymentAddressHelper()->createSampleMultisigPaymentAddress();

        $api_tester = $this->getAPITester();
        $api_tester->testAddErrors([
            [
                'postVars' => [
                    'destination' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j',
                    'quantity'    => 0,
                    'feePerKB'    => 0.00002500,
                    'asset'       => 'SOUP',
                ],
                'expectedErrorString' => 'quantity is invalid',
            ],
            [
                'postVars' => [
                    'destination' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j',
                    'quantity'    => 5,
                    'feePerKB'    => 0,
                    'asset'       => 'SOUP',
                ],
                'expectedErrorString' => 'fee per k b is invalid',
            ],
            [
                'postVars' => [
                    'quantity'    => 5,
                    'feePerKB'    => 0.00002500,
                    'asset'       => '',
                ],
                'expectedErrorString' => 'asset field is required',
            ],
        ], '/'.$payment_address['uuid']);
    }

    public function testAPIAddMultisigIssuance() {
        // install mocks
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $mock = app('CopayClientMockHelper')->mockCopayClient()->shouldIgnoreMissing();
        $copay_client_sign_args = [];
        $mock->shouldReceive('proposePublishAndSignTransaction')->once()
            ->andReturnUsing(function() use (&$copay_client_sign_args) {
                // save passed args
                $args = func_get_args();
                foreach($args as $_k => $_v) { $copay_client_sign_args[$_k] = $_v; }

                return ['id' => '11111111-2222-3333-4444-a94fbfbddfbd','bar'=>'baz'];
            });

        // create a multisig payment address
        $payment_address = $this->paymentAddressHelper()->createSampleMultisigPaymentAddress();

        $api_tester = $this->getAPITester();
        $posted_vars = app('SampleSendsHelper')->sampleMultisigIssuancePostVars();
        $issuance_response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/multisig/issuances/'.$payment_address['uuid'], $posted_vars, 200);
        PHPUnit::assertEquals('11111111-2222-3333-4444-a94fbfbddfbd', $issuance_response['txProposalId']);
        PHPUnit::assertEquals('baz', $issuance_response['copayTransaction']['bar']);
        PHPUnit::assertEquals('MYNEWASSET', $issuance_response['asset']);
        PHPUnit::assertEquals(25, $issuance_response['quantity']);

        PHPUnit::assertEquals([
            'counterpartyType' => 'issuance',
            'amountSat'        => '2500000000',
            'token'            => 'MYNEWASSET',
            'divisible'        => true,
            'description'      => 'hello world',
            'feePerKBSat'      => 50000,
        ], $copay_client_sign_args[1]);

        Mockery::close();
    }




    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester = app('SimpleAPITester', [$this->app, '/api/v1/multisig/issuances', app('App\Repositories\SendRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function paymentAddressHelper() {
        if (!isset($this->payment_address_helper)) { $this->payment_address_helper = app('PaymentAddressHelper'); }
        return $this->payment_address_helper;
    }


}
