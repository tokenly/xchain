<?php

use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Repositories\TXORepository;
use Illuminate\Support\Facades\DB;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class PrimeAPITest extends TestCase {

    protected $useRealSQLiteDatabase = true;

    public function testRequireAuthForPrimes() {
        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth('GET', $payment_address['uuid']);
    }

    public function testAPIErrorsGetPrime()
    {
        // mock the xcp sender
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();
        $api_tester->testGetErrors([
            [
                'postVars' => [
                    'size' => -1,
                ],
                'expectedErrorString' => 'Invalid size',
            ],
            [
                'method' => 'POST',
                'postVars' => [
                    'size'    => 2,
                    'count'   => 2,
                    'fee'     => 0.0001,
                    'feeRate' => 80,
                ],
                'expectedErrorString' => 'You cannot specify a fee rate and a fee',
            ],
        ], '/'.$payment_address['uuid']);
    }


    public function testAPIGetPrimes()
    {
        list($payment_address, $sample_txos) = $this->setupPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $response = $api_tester->callAPIWithAuthentication('GET', '/api/v1/primes/'.$payment_address['uuid'], ['size' => 0.00002]);
        $api_data = json_decode($response->getContent(), true);
        // echo "\$api_data: ".json_encode($api_data, 192)."\n";
        PHPUnit::assertEquals(1, $api_data['primedCount']);
        PHPUnit::assertEquals(7, $api_data['totalCount']);
        PHPUnit::assertCount(7, $api_data['utxos']);
    }

    public function testAPINoPrimeCreated()
    {
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(2000),
            'count' => 1,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        $api_data = json_decode($response->getContent(), true);

        PHPUnit::assertEquals(1, $api_data['oldPrimedCount']);
        PHPUnit::assertEquals(1, $api_data['newPrimedCount']);
        PHPUnit::assertEquals(null, $api_data['txid']);
        PHPUnit::assertEquals(false, $api_data['primed']);
    }

    public function testAPICreatePrimes_1()
    {
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(5430),
            'count' => 3,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        $api_data = json_decode($response->getContent(), true);

        PHPUnit::assertEquals(0, $api_data['oldPrimedCount']);
        PHPUnit::assertEquals(3, $api_data['newPrimedCount']);
        PHPUnit::assertNotEmpty($api_data['txid']);
        PHPUnit::assertEquals(true, $api_data['primed']);

        // check the composed transaction
        $composed_transaction_raw = DB::connection('untransacted')->table('composed_transactions')->first();
        $send_data = app('TransactionComposerHelper')->parseBTCTransaction($composed_transaction_raw->transaction);
        $bitcoin_address = $payment_address['address'];
        PHPUnit::assertCount(3, $send_data['change']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['destination']);
        PHPUnit::assertEquals(5430, $send_data['btc_amount']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][0][0]);
        PHPUnit::assertEquals(5430, $send_data['change'][0][1]);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][1][0]);
        PHPUnit::assertEquals(5430, $send_data['change'][1][1]);
    }

    public function testAPICreatePrimes_2()
    {
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(5430),
            'count' => 2,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        $api_data = json_decode($response->getContent(), true);

        PHPUnit::assertEquals(0, $api_data['oldPrimedCount']);
        PHPUnit::assertEquals(2, $api_data['newPrimedCount']);
        PHPUnit::assertEquals(true, $api_data['primed']);

        // check the composed transaction
        $composed_transaction_raw = DB::connection('untransacted')->table('composed_transactions')->first();
        $send_data = app('TransactionComposerHelper')->parseBTCTransaction($composed_transaction_raw->transaction);
        $bitcoin_address = $payment_address['address'];
        PHPUnit::assertCount(2, $send_data['change']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['destination']);
        PHPUnit::assertEquals(5430, $send_data['btc_amount']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][0][0]);
        PHPUnit::assertEquals(5430, $send_data['change'][0][1]);
    }



    public function testFeeRateCreatePrimesAPI()
    {
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(5430),
            'count' => 3,
            'feeRate' => 85,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        $api_data = json_decode($response->getContent(), true);

        PHPUnit::assertEquals(0, $api_data['oldPrimedCount']);
        PHPUnit::assertEquals(3, $api_data['newPrimedCount']);
        PHPUnit::assertNotEmpty($api_data['txid']);
        PHPUnit::assertEquals(true, $api_data['primed']);

        // check the composed transaction
        $composed_transaction_raw = DB::connection('untransacted')->table('composed_transactions')->first();
        $send_data = app('TransactionComposerHelper')->parseBTCTransaction($composed_transaction_raw->transaction, $sample_txos);
        $bitcoin_address = $payment_address['address'];
        PHPUnit::assertCount(3, $send_data['change']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['destination']);
        PHPUnit::assertEquals(5430, $send_data['btc_amount']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][0][0]);
        PHPUnit::assertEquals(5430, $send_data['change'][0][1]);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][1][0]);
        PHPUnit::assertEquals(5430, $send_data['change'][1][1]);
        
        PHPUnit::assertEquals(85, $send_data['fee_per_byte']);

    }

    public function testFeeRateTooHighCreatePrimesAPI()
    {
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupSimpleSmallPrimes(3);

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(30000),
            'count' => 5,
            'feeRate' => 85,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        PHPUnit::assertEquals(400, $response->getStatusCode());
        $api_data = json_decode($response->getContent(), true);
        PHPUnit::assertEquals('Unable to create any new primed transactions', $api_data['errors'][0]);
    }

    public function testTooHighFeeRateCreatePrimesAPI_1() {
        // what happens when the fee rate is too high to create any number of primes?

        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupSimpleSmallPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(5430),
            'count' => 3,
            'feeRate' => 120,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        $api_data = json_decode($response->getContent(), true);
        PHPUnit::assertEquals(400, $response->getStatusCode());
        $api_data = json_decode($response->getContent(), true);
        PHPUnit::assertEquals('Unable to create any new primed transactions', $api_data['errors'][0]);
    }

    public function testTooHighFeeRateCreatePrimesAPI_2() {
        // what happens when the fee rate is too high to create all of the desired number of primes?

        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        // setup
        list($payment_address, $sample_txos) = $this->setupSimpleSmallPrimes();

        // api tester
        $api_tester = $this->getAPITester();
        $create_primes_vars = [
            'size'  => CurrencyUtil::satoshisToValue(5430),
            'count' => 3,
            'feeRate' => 40,
        ];
        $response = $api_tester->callAPIWithAuthentication('POST', '/api/v1/primes/'.$payment_address['uuid'], $create_primes_vars);
        $api_data = json_decode($response->getContent(), true);
        PHPUnit::assertArrayNotHasKey('message', $api_data, "Found unexpected message: ".(isset($api_data['message']) ? $api_data['message'] : null));

        PHPUnit::assertEquals(0, $api_data['oldPrimedCount']);
        PHPUnit::assertEquals(2, $api_data['newPrimedCount']);
        PHPUnit::assertNotEmpty($api_data['txid']);
        PHPUnit::assertEquals(true, $api_data['primed']);

        // check the composed transaction
        $composed_transaction_raw = DB::connection('untransacted')->table('composed_transactions')->first();
        $send_data = app('TransactionComposerHelper')->parseBTCTransaction($composed_transaction_raw->transaction, $sample_txos);
        $bitcoin_address = $payment_address['address'];
        PHPUnit::assertCount(2, $send_data['change']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['destination']);
        PHPUnit::assertEquals(5430, $send_data['btc_amount']);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][0][0]);
        PHPUnit::assertEquals(5430, $send_data['change'][0][1]);
        PHPUnit::assertEquals($bitcoin_address, $send_data['change'][1][0]);
        PHPUnit::assertEquals(8740, $send_data['change'][1][1]);
        
        PHPUnit::assertEquals(40, $send_data['fee_per_byte']);

        // echo "\$send_data: ".json_encode($send_data, 192)."\n";
    }


/*
$api_data: {
    "primedCount": 1,
    "totalCount": 1,
    "txos": [
        {
            "txid": "1111111111111111111111111111111111111111111111111111111111110001",
            "n": "0",
            "amount": 1,
            "type": "confirmed",
            "green": true
        }
    ]
}
*/


    // ------------------------------------------------------------------------
    
    protected function getAPITester($url='/api/v1/primes') {
        $api_tester =  $this->app->make('SimpleAPITester', [$this->app, $url, app('App\Repositories\TXORepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

    protected function setupPrimes() {
        // mock the xcp sender
        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        // make all TXOs (with roughly 0.5 BTC)
        $txo_helper = app('SampleTXOHelper');
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 1000,  'n' => 0]);
        $sample_txos[1] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2000,  'n' => 1]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[2] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 7000,  'n' => 0]);
        $sample_txos[3] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 8000,  'n' => 1]);
        $sample_txos[4] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9000,  'n' => 2]);
        $sample_txos[5] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 10000,  'n' => 3]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[6] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 50000000, 'n' => 2]);

        return [$payment_address, $sample_txos];
    }


    protected function setupSimpleSmallPrimes($count=1) {
        // mock the xcp sender
        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        // make all TXOs (with roughly 0.5 BTC)
        $txo_helper = app('SampleTXOHelper');
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        for ($i=0; $i < $count; $i++) { 
            $sample_txos[$i] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 30000,  'n' => $i]);
        }

        return [$payment_address, $sample_txos];
    }



}
