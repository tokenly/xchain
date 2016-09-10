<?php

use App\Models\TXO;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class PaymentAddressSenderTest extends TestCase {

    protected $useRealSQLiteDatabase = true;

    public function testPaymentAddressSenderForCounterpartySend() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $dust_size = 0.00001234;
        $sender->sendByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '100', 'TOKENLY', $float_fee=null, $dust_size, $is_sweep=false);

        // check the first sent call
        $send_details = app('TransactionComposerHelper')->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0]);

        PHPUnit::assertEquals('1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i',      $send_details['destination']);
        PHPUnit::assertEquals('TOKENLY',                                 $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100),        $send_details['quantity']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.4999),    $send_details['sum_out']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis($dust_size), $send_details['btc_dust_size']);

        // make sure that the composed transaction is correct
        $composed_transaction_repository = app('App\Repositories\ComposedTransactionRepository');
        $composed_tx_model = $composed_transaction_repository->getComposedTransactionByRequestID('request001');
        PHPUnit::assertNotEmpty($composed_tx_model['txid']);
        PHPUnit::assertNotEmpty($composed_tx_model['transaction']);
        PHPUnit::assertNotEmpty($composed_tx_model['utxos']);
        PHPUnit::assertEquals($input_utxos[6]['txid'].':'.$input_utxos[6]['n'], $composed_tx_model['utxos'][0]['txid'].':'.$composed_tx_model['utxos'][0]['n']);
        PHPUnit::assertEquals('request001', $composed_tx_model['request_id']);

    }


    //  make sure we don't push a duplicate to the network
    public function testPaymentAddressDuplicateSenderForCounterpartySend() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $dust_size = 0.00001234;
        $sender->sendByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '100', 'TOKENLY', $float_fee=null, $dust_size, $is_sweep=false);

        // check the first sent call
        PHPUnit::assertCount(2, $mock_calls['xcpd']); // get_asset_info and get_issuances
        PHPUnit::assertCount(1, $mock_calls['btcd']); // sendrawtransaction
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][0]['method']); // sendrawtransaction

        // send the same thing again
        $sender->sendByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '100', 'TOKENLY', $float_fee=null, $dust_size, $is_sweep=false);
        PHPUnit::assertCount(2, $mock_calls['xcpd']); // get_asset_info and get_issuances
        PHPUnit::assertCount(2, $mock_calls['btcd']); // sendrawtransaction, sendrawtransaction
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][0]['method']); // sendrawtransaction
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][1]['method']); // sendrawtransaction

        // make sure tx was the same
        PHPUnit::assertEquals($mock_calls['btcd'][0]['args'][0], $mock_calls['btcd'][1]['args'][0]);

    }



    public function testPaymentAddressSenderForBTCSend()
    {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $sender->send($payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);

        // no xcpd calls
        PHPUnit::assertEmpty($mock_calls['xcpd']);

        // check the first sent call to bitcoind
        $btcd_send_call = $mock_calls['btcd'][0];
        PHPUnit::assertEquals('sendrawtransaction', $btcd_send_call['method']); // sendrawtransaction
        $send_details = app('TransactionComposerHelper')->parseBTCTransaction($btcd_send_call['args'][0]);
        PHPUnit::assertEquals('1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $send_details['destination']);

        // check output amounts
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.123), $send_details['btc_amount']);
        $change_amount_satoshis = 50000000 - 10000 - CurrencyUtil::valueToSatoshis(0.123);
        PHPUnit::assertEquals($change_amount_satoshis, $send_details['change'][0][1]);

        // check that the change utxo is green
        $txo_repository = app('App\Repositories\TXORepository');
        $txo = $txo_repository->findByTXIDAndOffset($send_details['txid'], 1);
        PHPUnit::assertTrue($txo['green']);

    }

    public function testPaymentAddressDuplicateSenderForBTCSend()
    {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);
        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sendByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);

        // check the first sent call
        PHPUnit::assertCount(1, $mock_calls['btcd']); // 1 sendrawtransaction only
        PHPUnit::assertEquals('sendrawtransaction',    $mock_calls['btcd'][0]['method']);

        // send the same thing again
        $sender->sendByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);
        PHPUnit::assertCount(2, $mock_calls['btcd']); // 2 sendrawtransaction only
        PHPUnit::assertEquals('sendrawtransaction',    $mock_calls['btcd'][0]['method']);
        PHPUnit::assertEquals('sendrawtransaction',    $mock_calls['btcd'][1]['method']);

        PHPUnit::assertEquals($mock_calls['btcd'][0]['args'][0], $mock_calls['btcd'][1]['args'][0]);

    }

    public function testPaymentAddressSenderForBTCSweep() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sweepBTC($payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $float_fee=null);

        // no xcpd calls
        PHPUnit::assertEmpty($mock_calls['xcpd']);

        // check the first sent call to bitcoind
        $btcd_send_call = $mock_calls['btcd'][0];
        PHPUnit::assertEquals('sendrawtransaction', $btcd_send_call['method']); // sendrawtransaction

        // check send details
        $send_details = app('TransactionComposerHelper')->parseBTCTransaction($btcd_send_call['args'][0]);
        PHPUnit::assertEquals('1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $send_details['destination']);
        PHPUnit::assertEquals(50019001 - 10000, $send_details['btc_amount']);
        PHPUnit::assertEmpty(0, $send_details['change']);

        // all TXOs are spent
        $txo_repository = app('App\Repositories\TXORepository');
        PHPUnit::assertCount(0, $txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true));
        PHPUnit::assertCount(7, $txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], false));


    }

    public function testPaymentAddressDuplicateSenderForBTCSweep() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sweepBTCByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $float_fee=null);

        // check the first sent call
        PHPUnit::assertCount(1, $mock_calls['btcd']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][0]['method']);

        // send the same thing again
        $sender->sweepBTCByRequestID('request001', $payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $float_fee=null);
        PHPUnit::assertCount(2, $mock_calls['btcd']); // sendrawtransaction x 2
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][0]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][1]['method']);
        PHPUnit::assertEquals($mock_calls['btcd'][0]['args'][0], $mock_calls['btcd'][1]['args'][0]);

    }

    public function testPaymentAddressSenderForSweepAllAssets() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSimpleSampleTXOs($user);

        // add balances
        $payment_address_helper = app('PaymentAddressHelper');
        $balances = ['TOKENLY' => 100, 'FOOCOIN' => 200, 'BTC' => 1];
        $payment_address_helper->addBalancesToPaymentAddressAccountWithoutUTXOs($balances, $payment_address);

        // sweep
        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sweepAllAssets($payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $float_fee=null);

        // check the sweep BTC calls
        //   3 BTC sendrawtransaction calls
        PHPUnit::assertCount(3, $mock_calls['btcd']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][0]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][1]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][2]['method']);


        // check the transaction details
        $transaction_composer_helper = app('TransactionComposerHelper');

        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][0]['args'][0]);
        PHPUnit::assertEquals('1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $send_details['destination']);
        PHPUnit::assertEquals('FOOCOIN', $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(200), $send_details['quantity']);

        $send_details = $transaction_composer_helper->parseCounterpartyTransaction($mock_calls['btcd'][1]['args'][0]);
        PHPUnit::assertEquals('1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', $send_details['destination']);
        PHPUnit::assertEquals('TOKENLY', $send_details['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $send_details['quantity']);

        $send_details = $transaction_composer_helper->parseBTCTransaction($mock_calls['btcd'][2]['args'][0]);

        // expect 49978141
        $expected_btc_sweep_amount = 100000000 - ((10000 + 5430) * 2) - 10000;
        PHPUnit::assertEquals($expected_btc_sweep_amount, $send_details['btc_amount']);
        PHPUnit::assertEmpty($send_details['change']);
    }

    public function testFailedPaymentAddressSend() {
        // setup bitcoind to return a -25 error
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this, ['sendrawtransaction' => function($hex, $allow_high_fees) {
            throw new \Exception("Test bitcoind error", -25);
        }]);

        $sender         = app('App\Blockchain\Sender\PaymentAddressSender');
        $txo_repository = app('App\Repositories\TXORepository');

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        try {
            $sender->send($payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);
        } catch (Exception $e) {
            // echo "Exception: ".get_class($e)."\n"."Exception Message: ".($e->getMessage())."\n"."Exception Code: ".($e->getCode())."\n"."transaction.parseError: ".$e->getTraceAsString()."\n";
            PHPUnit::assertEquals(-25, $e->getCode());
        }

        // no composed transactions remain
        $all_transactions = DB::connection('untransacted')->table('composed_transactions')->get();
        PHPUnit::assertCount(0, $all_transactions);

        // all TXOs are unspent
        PHPUnit::assertCount(7, $txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true));
    }



    public function testPaymentAddressSenderForBTCMultiSend()
    {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $destinations = [
            ['1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', 0.0001],
            ['1ATEST222XXXXXXXXXXXXXXXXXXXYzLVeV', 0.0001],
            ['1ATEST333XXXXXXXXXXXXXXXXXXXatH8WE', 0.1000],
        ];


        $sender->send($payment_address, $destinations, '0.1002', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);

        // check the first sent call to bitcoind
        $btcd_send_call = $mock_calls['btcd'][0];
        PHPUnit::assertEquals('sendrawtransaction', $btcd_send_call['method']); // sendrawtransaction
        $send_details = app('TransactionComposerHelper')->parseBTCTransaction($btcd_send_call['args'][0]);
        PHPUnit::assertEquals('1ATEST111XXXXXXXXXXXXXXXXXXXXwLHDB', $send_details['destination']);

        // check output amounts
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.0001), $send_details['btc_amount']);
        $change_amount_satoshis = 50000000 - 10000 - CurrencyUtil::valueToSatoshis(0.123);
        
        // 3 change addresses
        PHPUnit::assertCount(3, $send_details['change']);

        PHPUnit::assertEquals('1ATEST222XXXXXXXXXXXXXXXXXXXYzLVeV', $send_details['change'][0][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.0001), $send_details['change'][0][1]);
        PHPUnit::assertEquals('1ATEST333XXXXXXXXXXXXXXXXXXXatH8WE', $send_details['change'][1][0]);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.100),  $send_details['change'][1][1]);

        // check that the change utxo exists and is green
        $txo_repository = app('App\Repositories\TXORepository');
        $txo = $txo_repository->findByTXIDAndOffset($send_details['txid'], 3);
        PHPUnit::assertTrue($txo['green']);

    }

    // ------------------------------------------------------------------------
    


    // total is 50019001 satoshis
    protected function makeAddressAndSampleTXOs($user=null) {
        $payment_address_helper = app('PaymentAddressHelper');
        $txo_helper             = app('SampleTXOHelper');

        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances($user, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // make all TXOs (with roughly 0.5 BTC)
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 1000,  'n' => 0]);
        $sample_txos[1] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2001,  'n' => 1]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[2] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2000,  'n' => 0]);
        $sample_txos[3] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 3000,  'n' => 1]);
        $sample_txos[4] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 4000,  'n' => 2]);
        $sample_txos[5] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 7000,  'n' => 3]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[6] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 50000000, 'n' => 2]);

        return [$payment_address, $sample_txos];
    }

    // total is 100000000 satoshis
    protected function makeAddressAndSimpleSampleTXOs($user=null) {
        $payment_address_helper = app('PaymentAddressHelper');
        $txo_helper             = app('SampleTXOHelper');

        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances($user, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // make all TXOs (with roughly 0.5 BTC)
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 100000000,  'n' => 0]);

        return [$payment_address, $sample_txos];
    }

}
