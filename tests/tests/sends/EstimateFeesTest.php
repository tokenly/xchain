<?php

use App\Blockchain\Sender\PaymentAddressSender;
use App\Models\TXO;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class EstimateFeesTest extends TestCase {

    protected $useRealSQLiteDatabase = true;


    public function testCalculateFeesForBTCSend()
    {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $fees_info = $sender->buildFeeEstimateInfo($payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '0.123', 'BTC', $dust_size=null, $is_sweep=false);
        $bytes = $fees_info['size'];
        PHPUnit::assertGreaterThanOrEqual(225, $bytes);
        PHPUnit::assertLessThan(230, $bytes);
        PHPUnit::assertEquals([
            'size' => $bytes,
            'fees' => [
                'low'     => (PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_LOW * $bytes),
                'med'     => (PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_MED * $bytes),
                'high'    => (PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes),
            ],
        ], $fees_info);
    }

    public function testCalculateFeesForCounterpartySend() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app('App\Blockchain\Sender\PaymentAddressSender');
        $dust_size = 0.00001234;
        $fees_info = $sender->buildFeeEstimateInfo($payment_address, '1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', '100', 'TOKENLY', $dust_size, $is_sweep=false);
        $bytes = $fees_info['size'];
        PHPUnit::assertGreaterThanOrEqual(400, $bytes);
        PHPUnit::assertLessThan(500, $bytes);
        PHPUnit::assertEquals([
            'size' => $bytes,
            'fees' => [
                'low'     => (PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_LOW * $bytes),
                'med'     => (PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_MED * $bytes),
                'high'    => (PaymentAddressSender::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes),
            ],
        ], $fees_info);

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

}
