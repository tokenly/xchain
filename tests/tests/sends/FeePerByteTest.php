<?php

use App\Blockchain\Sender\PaymentAddressSender;
use App\Models\TXO;
use App\Repositories\TXORepository;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class FeePerByteTest extends TestCase {

    protected $useDatabase = true;

    public function testFeeCoinSelection_1() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app(PaymentAddressSender::class);
        $fee_per_byte = 50;
        $unsigned_transaction = $sender->composeUnsignedTransaction($payment_address, '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU', 100, 'TOKENLY', $_change_address_collection=null, $_float_fee=null, $fee_per_byte);

        // print "\nTXOS:\n";
        // print $this->debugDumpUTXOs($unsigned_transaction->getInputUtxos())."\n";

        // sign the transaction to get the correct size
        // echo "\$isSigned: ".json_encode($unsigned_transaction->getSigned(), 192)."\n";


        // size is 264 bytes
        PHPUnit::assertEquals(13200, $unsigned_transaction->feeSatoshis());
        PHPUnit::assertEquals(1, count($unsigned_transaction->getInputUtxos()));
        // PHPUnit::assertEquals(50, $unsigned_transaction->getSatoshisPerByte());
    }

    public function testFeeCoinSelection_2() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app(PaymentAddressSender::class);
        $fee_per_byte = 50;
        $change_address_collection = null;
        $unsigned_transaction = $sender->composeUnsignedTransaction($payment_address, '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU', CurrencyUtil::satoshisToValue(1000), 'BTC', $change_address_collection, $float_fee=null, $fee_per_byte);

        // print "\nTXOS:\n";
        // print $this->debugDumpUTXOs($unsigned_transaction->getInputUtxos())."\n";

        // size is 225 bytes
        PHPUnit::assertEquals(11250, $unsigned_transaction->feeSatoshis());
        PHPUnit::assertEquals(1, count($unsigned_transaction->getInputUtxos()));
    }


    // test when a little higher fee ratio would be better
    public function testFeeCoinSelection_3() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();

        $payment_address_helper = app('PaymentAddressHelper');
        $txo_helper             = app('SampleTXOHelper');

        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances($user, ['address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j']);

        // make all TXOs (with roughly 0.5 BTC)
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9999,  'n' => 0]);
        $sample_txos[1] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9999,  'n' => 1]);
        $sample_txos[2] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9999,  'n' => 2]);
        $sample_txos[3] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9999,  'n' => 3]);
        $sample_txos[4] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9999,  'n' => 4]);
        $sample_txos[5] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 9999,  'n' => 5]);

        $sender = app(PaymentAddressSender::class);
        $fee_per_byte = 10;
        $change_address_collection = null;
        $unsigned_transaction = $sender->composeUnsignedTransaction($payment_address, '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU', CurrencyUtil::satoshisToValue(10000), 'BTC', $change_address_collection, $float_fee=null, $fee_per_byte);
        PHPUnit::assertNotEmpty($unsigned_transaction);

        // print "\nTXOS:\n";
        // print $this->debugDumpUTXOs($unsigned_transaction->getInputUtxos())."\n";
        // echo "\$unsigned_transaction->getSatoshisPerByte(): ".json_encode($unsigned_transaction->getSatoshisPerByte(), 192)."\n";
        // echo "\$unsigned_transaction->feeSatoshis(): ".json_encode($unsigned_transaction->feeSatoshis(), 192)."\n";

        // 3720 = 10 + (147*2) + (34*2)
        PHPUnit::assertEquals(3720, $unsigned_transaction->feeSatoshis());
        PHPUnit::assertEquals(2, count($unsigned_transaction->getInputUtxos()));
    }


    public function testSweepFeeCoinSelection_1() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSimpleSampleTXOs($user);

        $sender = app(PaymentAddressSender::class);
        $is_sweep = true;
        $fee_per_byte = 500;
        $unsigned_transaction = $sender->composeUnsignedTransaction($payment_address, '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU', 0, 'BTC', $_change_address_collection=null, $_float_fee=null, $fee_per_byte, $_float_btc_dust_size=null, $is_sweep);

        // size for a single input/single output is 191 bytes
        PHPUnit::assertEquals(95500, $unsigned_transaction->feeSatoshis());
        PHPUnit::assertEquals(1, count($unsigned_transaction->getInputUtxos()));
    }

    public function testSweepFeeCoinSelection_2() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTXOs($user);

        $sender = app(PaymentAddressSender::class);
        $is_sweep = true;
        $fee_per_byte = 500;
        $unsigned_transaction = $sender->composeUnsignedTransaction($payment_address, '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU', 0, 'BTC', $_change_address_collection=null, $_float_fee=null, $fee_per_byte, $_float_btc_dust_size=null, $is_sweep);

        // size is 1073 bytes
        PHPUnit::assertEquals(7, count($unsigned_transaction->getInputUtxos()));
        PHPUnit::assertEquals(1073*500, $unsigned_transaction->feeSatoshis());
    }


    /**
     * @expectedException App\Blockchain\Sender\Exception\CompositionException
     * @expectedExceptionMessage The fee rate was too high to sweep all of the UTXOs
     */
    public function testSweepFeeCoinSelectionTooSmall_3() {
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        list($payment_address, $input_utxos) = $this->makeAddressAndSampleTinyTXOs($user);

        $sender = app(PaymentAddressSender::class);
        $is_sweep = true;
        $fee_per_byte = 350;
        $unsigned_transaction = $sender->composeUnsignedTransaction($payment_address, '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU', 0, 'BTC', $_change_address_collection=null, $_float_fee=null, $fee_per_byte, $_float_btc_dust_size=null, $is_sweep);
    }



    // ------------------------------------------------------------------------

    // total is 50019001 satoshis
    protected function makeAddressAndSampleTXOs($user=null) {
        $payment_address_helper = app('PaymentAddressHelper');
        $txo_helper             = app('SampleTXOHelper');

        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances($user, ['address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j']);

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

        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances($user, ['address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j']);

        // make all TXOs (with roughly 0.5 BTC)
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 100000000,  'n' => 0]);

        return [$payment_address, $sample_txos];
    }

    // total is 6000 satoshis
    protected function makeAddressAndSampleTinyTXOs($user=null) {
        $payment_address_helper = app('PaymentAddressHelper');
        $txo_helper             = app('SampleTXOHelper');

        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances($user, ['address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j']);

        // make 3 TXOs (with 0.00006000 BTC)
        $sample_txos = [];
        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 1000,  'n' => 0]);
        $sample_txos[1] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2000,  'n' => 1]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[2] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 3000,  'n' => 0]);

        return [$payment_address, $sample_txos];
    }


    protected function debugDumpUTXOs($utxos) {
        $out = '';
        $out .= 'total utxos: '.count($utxos)."\n";
        foreach($utxos as $utxo) {
            $out .= $utxo['amount']."\n";
        }
        return rtrim($out);
    }


}
