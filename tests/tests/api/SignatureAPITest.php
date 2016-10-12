<?php

use App\Models\TXO;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use Illuminate\Support\Facades\Queue;
use \PHPUnit_Framework_Assert as PHPUnit;

class SignatureAPITest extends TestCase {

    protected $useDatabase = true;
    // protected $useRealSQLiteDatabase = true;

    public function testSignMessageAPI()
    {
        $payment_address = $this->paymentAddressHelper()->createSamplePaymentAddress();
        
        $message = 'hi';
        $api_tester = $this->getAPITester();

        // post api/v1/message/sign/{address} API\AddressController@signMessage
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/message/sign/'.$payment_address['address'], [
            'message' => $message,
        ]);

        // check signature
        PHPUnit::assertEquals('IEo8BErYJ+I8RizbPDERE4V1moyYQSbfJdTKJb/uo8WOMpOjtiIjY5hMeKUlzcMIEz5PdNen1wV9EJZoAxkbozU=', $result['result']);
    }


    public function testVerifyMessageAPI()
    {
        $payment_address = $this->paymentAddressHelper()->createSamplePaymentAddress();
        
        $message = 'hi';
        $api_tester = $this->getAPITester();

        // get api/v1/message/verify/{address} API\AddressController@verifyMessage'

        // valid
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/message/verify/'.$payment_address['address'], [
            'message' => $message,
            'sig'     => 'IEo8BErYJ+I8RizbPDERE4V1moyYQSbfJdTKJb/uo8WOMpOjtiIjY5hMeKUlzcMIEz5PdNen1wV9EJZoAxkbozU=',
        ]);
        PHPUnit::assertTrue($result['result']);

        // bad signature
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/message/verify/'.$payment_address['address'], [
            'message' => $message,
            'sig'     => 'badsign',
        ]);
        PHPUnit::assertFalse($result['result']);

        // wrong message
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/message/verify/'.$payment_address['address'], [
            'message' => 'othermessage',
            'sig'     => 'IEo8BErYJ+I8RizbPDERE4V1moyYQSbfJdTKJb/uo8WOMpOjtiIjY5hMeKUlzcMIEz5PdNen1wV9EJZoAxkbozU=',
        ]);
        PHPUnit::assertFalse($result['result']);

        // wrong address
        $result = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/message/verify/1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j', [
            'message' => $message,
            'sig'     => 'IEo8BErYJ+I8RizbPDERE4V1moyYQSbfJdTKJb/uo8WOMpOjtiIjY5hMeKUlzcMIEz5PdNen1wV9EJZoAxkbozU=',
        ]);
        PHPUnit::assertFalse($result['result']);

    }


    // ------------------------------------------------------------------------
    
    
    protected function getAPITester() {
        $api_tester = app('SimpleAPITester', [$this->app, '/api/v1/', app('App\Repositories\PaymentAddressRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function paymentAddressHelper() {
        if (!isset($this->payment_address_helper)) { $this->payment_address_helper = app('PaymentAddressHelper'); }
        return $this->payment_address_helper;
    }

}
