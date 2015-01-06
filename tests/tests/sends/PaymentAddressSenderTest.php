<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class PaymentAddressSenderTest extends TestCase {

    protected $useDatabase = true;

    public function testPaymentAddressSenderForCounterpartySend()
    {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->send($payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '100', 'TOKENLY', $float_fee=null, $multisig_dust_size=null, $is_sweep=false);

        // check the first sent call
        PHPUnit::assertEquals($payment_address['address'], $mock_calls['xcpd'][0]['args'][0]['source']);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_calls['xcpd'][0]['args'][0]['destination']);
        PHPUnit::assertEquals(100, $mock_calls['xcpd'][0]['args'][0]['quantity']);
        PHPUnit::assertEquals('TOKENLY', $mock_calls['xcpd'][0]['args'][0]['asset']);
        PHPUnit::assertEquals(0.0001, $mock_calls['xcpd'][0]['args'][0]['fee_per_kb']);


    }

    public function testPaymentAddressSenderForBTCSend()
    {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $sender = $this->app->make('App\Blockchain\Sender\PaymentAddressSender');
        $sender->send($payment_address, '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', '0.123', 'BTC', $float_fee=null, $multisig_dust_size=null, $is_sweep=false);

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
        PHPUnit::assertEquals(0.1119, $mock_calls['btcd'][0]['args'][1][$second_output_address]);
        // echo "\$mock_calls['btcd'][0]['args'][1]:\n".json_encode($mock_calls['btcd'][0]['args'][1], 192)."\n";
    }

    public function testPaymentAddressSenderForBTCSweep() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

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


}
