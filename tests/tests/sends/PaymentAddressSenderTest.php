<?php

use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class PaymentAddressSenderTest extends TestCase {

    protected $useRealSQLiteDatabase = true;

    public function testPaymentAddressSenderForCounterpartySend()
    {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $dust_size = 0.00001234;
        $sender->send($payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '100', 'TOKENLY', $float_fee=null, $dust_size, $is_sweep=false);

        // check the first sent call
        $mock_send_call = $mock_calls['xcpd'][1];
        PHPUnit::assertEquals($payment_address['address'], $mock_send_call['args'][0]['source']);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_send_call['args'][0]['destination']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $mock_send_call['args'][0]['quantity']);
        PHPUnit::assertEquals('TOKENLY', $mock_send_call['args'][0]['asset']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(0.0001), $mock_send_call['args'][0]['fee_per_kb']);

        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis($dust_size), $mock_send_call['args'][0]['regular_dust_size']);
    }

    //  make sure we don't push a duplicate to the network
    public function testPaymentAddressDuplicateSenderForCounterpartySend() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $dust_size = 0.00001234;
        $sender->sendByRequestID('request001', $payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '100', 'TOKENLY', $float_fee=null, $dust_size, $is_sweep=false);

        // check the first sent call
        PHPUnit::assertCount(2, $mock_calls['xcpd']); // get_asset_info and create_send
        PHPUnit::assertCount(2, $mock_calls['btcd']); // signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('signrawtransaction', $mock_calls['btcd'][0]['method']); // signrawtransaction and sendrawtransaction

        // send the same thing again
        $sender->sendByRequestID('request001', $payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '100', 'TOKENLY', $float_fee=null, $dust_size, $is_sweep=false);
        PHPUnit::assertCount(2, $mock_calls['xcpd']); // get_asset_info and create_send
        PHPUnit::assertCount(3, $mock_calls['btcd']); // signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('signrawtransaction', $mock_calls['btcd'][0]['method']); // signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][1]['method']); // sendrawtransaction
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][2]['method']); // sendrawtransaction
    }



    public function testPaymentAddressSenderForBTCSend()
    {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->send($payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);

        // no xcpd calls
        PHPUnit::assertEmpty($mock_calls['xcpd']);

        // check insight call
        PHPUnit::assertCount(1, $mock_calls['insight']['insight']);

        // check the first sent call to bitcoind
        PHPUnit::assertEquals('dbfdc2a0d22a8282c4e7be0452d595695f3a39173bed4f48e590877382b112fc', $mock_calls['btcd'][0]['args'][0][0]['txid']);
        PHPUnit::assertArrayHasKey('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_calls['btcd'][0]['args'][1]);
        // 2 destinations
        PHPUnit::assertCount(2, $mock_calls['btcd'][0]['args'][1]);
        // check output amounts
        $first_output_address = array_keys($mock_calls['btcd'][0]['args'][1])[0];
        $second_output_address = array_keys($mock_calls['btcd'][0]['args'][1])[1];
        PHPUnit::assertEquals(0.123, $mock_calls['btcd'][0]['args'][1][$first_output_address]);
        PHPUnit::assertEquals(0.1109, $mock_calls['btcd'][0]['args'][1][$second_output_address]);
        // echo "\$mock_calls['btcd'][0]['args'][1]:\n".json_encode($mock_calls['btcd'][0]['args'][1], 192)."\n";
    }

    public function testPaymentAddressDuplicateSenderForBTCSend()
    {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);
        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sendByRequestID('request001', $payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);

        // check the first sent call
        PHPUnit::assertCount(3, $mock_calls['btcd']); // createrawtransaction, signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('signrawtransaction', $mock_calls['btcd'][1]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][2]['method']);

        // send the same thing again
        $sender->sendByRequestID('request001', $payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '0.123', 'BTC', $float_fee=null, $dust_size=null, $is_sweep=false);
        PHPUnit::assertCount(4, $mock_calls['btcd']); // createrawtransaction, signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('signrawtransaction', $mock_calls['btcd'][1]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][2]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][3]['method']);
    }

    public function testPaymentAddressSenderForBTCSweep() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sweepBTC($payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $float_fee=null);

        // no xcpd calls
        PHPUnit::assertEmpty($mock_calls['xcpd']);

        // check insight call
        PHPUnit::assertCount(1, $mock_calls['insight']['insight']);

        // check the first sent call to bitcoind
        PHPUnit::assertEquals('dbfdc2a0d22a8282c4e7be0452d595695f3a39173bed4f48e590877382b112fc', $mock_calls['btcd'][0]['args'][0][0]['txid']);
        PHPUnit::assertArrayHasKey('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_calls['btcd'][0]['args'][1]);
        // 1 destination
        PHPUnit::assertCount(1, $mock_calls['btcd'][0]['args'][1]);
        // check output amount
        $first_output_address = array_keys($mock_calls['btcd'][0]['args'][1])[0];
        PHPUnit::assertEquals(0.2349, $mock_calls['btcd'][0]['args'][1][$first_output_address]);
    }

    public function testPaymentAddressDuplicateSenderForBTCSweep() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sweepBTCByRequestID('request001', $payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $float_fee=null);

        // check the first sent call
        PHPUnit::assertCount(3, $mock_calls['btcd']); // createrawtransaction, signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('signrawtransaction', $mock_calls['btcd'][1]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][2]['method']);

        // send the same thing again
        $sender->sweepBTCByRequestID('request001', $payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $float_fee=null);
        PHPUnit::assertCount(4, $mock_calls['btcd']); // createrawtransaction, signrawtransaction and sendrawtransaction
        PHPUnit::assertEquals('signrawtransaction', $mock_calls['btcd'][1]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][2]['method']);
        PHPUnit::assertEquals('sendrawtransaction', $mock_calls['btcd'][3]['method']);
    }

    public function testPaymentAddressSenderForSweepAllAssets() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $insight_mock_calls = $this->buildInsightMockCallsForSweepAllAssets();

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->sweepAllAssets($payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $float_fee=null);

        // 3 xcpd calls
        //   1 balance check and 2 sends
        PHPUnit::assertCount(3, $mock_calls['xcpd']);
        PHPUnit::assertEquals('get_balances', $mock_calls['xcpd'][0]['method']);
        PHPUnit::assertEquals('FOOCOIN', $mock_calls['xcpd'][1]['args'][0]['asset']);
        PHPUnit::assertEquals('100', $mock_calls['xcpd'][1]['args'][0]['quantity']);
        PHPUnit::assertEquals('BARCOIN', $mock_calls['xcpd'][2]['args'][0]['asset']);
        PHPUnit::assertEquals('200', $mock_calls['xcpd'][2]['args'][0]['quantity']);

        // check insight call
        PHPUnit::assertCount(2, $insight_mock_calls['insight']);

        // check the sweep BTC calls
        //   each xcpd send is 2 calls, and the btc sweep is 3 calls
        PHPUnit::assertCount(7, $mock_calls['btcd']);
        $btc_create_tx = $mock_calls['btcd'][4];
        $btc_create_tx_args = $btc_create_tx['args'];
        PHPUnit::assertEquals('dbfdc2a0d22a8282c4e7be0452d595695f3a39173bed4f48e590877382b112fc', $btc_create_tx_args[0][0]['txid']);
        PHPUnit::assertArrayHasKey('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $btc_create_tx_args[1]);
        // check destination and output amount
        PHPUnit::assertCount(1, $btc_create_tx_args[1]);
        $first_output_address = array_keys($btc_create_tx_args[1])[0];
        PHPUnit::assertEquals(0.2349, $btc_create_tx_args[1][$first_output_address]);
    }



    protected function buildInsightMockCallsForSweepAllAssets() {
        $builder = app('InsightAPIMockBuilder');
        $calls_count = 0;
        $getTransactionCallback = function($txid) use (&$calls_count) {
            ++$calls_count;
            if ($calls_count <= 1) { throw new Exception("Not Found", 404); }
            // echo "Loading tx $txid\n";
            $filepath = base_path().'/tests/fixtures/api/_tx_000000000000000000000000000000000000000000000000000000000001ba5e.json';
            $base_tx = json_decode(file_get_contents($filepath), true);
            return $base_tx;
        };
        return $builder->installMockInsightClient($this->app, $this, ['getTransaction' => $getTransactionCallback]);
    }

}
