<?php

use App\Blockchain\Sender\TXOChooser;
use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Providers\TXO\Facade\TXOHandler;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class TXOChooserTest extends TestCase {

    protected $useDatabase = true;

    public function testChooseTXOs()
    {
        // receiving a transaction adds TXOs
        $txo_repository = app('App\Repositories\TXORepository');
        $txo_chooser    = app('App\Blockchain\Sender\TXOChooser');

        $float = function($i) { return CurrencyUtil::satoshisToValue($i); };

        // samples [1000, 2000, 2000, 3000, 4000, 50000]
        list($payment_address, $sample_txos) = $this->makeAddressAndSampleTXOs();

        // exact
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(900), $float(100), 0);
        $this->assertFound([0], $sample_txos, $chosen_txos);
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(1900), $float(100), 0);
        $this->assertFound([2], $sample_txos, $chosen_txos);
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(2900), $float(100), 0);
        $this->assertFound([3], $sample_txos, $chosen_txos);

        // low
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(4900), $float(100), 0);
        $this->assertFound([3,2], $sample_txos, $chosen_txos);
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(7900), $float(100), 0);
        $this->assertFound([5,0], $sample_txos, $chosen_txos);
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(7899), $float(100), 0);
        $this->assertFound([5,0], $sample_txos, $chosen_txos);
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(11900), $float(100), 0);
        $this->assertFound([5,3,2], $sample_txos, $chosen_txos);

        // high
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(19000), $float(100), 0);
        $this->assertFound([6], $sample_txos, $chosen_txos);

        // choose high or low
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(18000), $float(100), 0);
        $this->assertFound([6], $sample_txos, $chosen_txos);

        // very high
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(63000), $float(0), 0);
        $this->assertFound([6,5,4,2], $sample_txos, $chosen_txos);

    }
/*
    6: 50000
    5:  7000
    4:  4000
    3:  3000
    2:  2000
    1:  2010
    0:  1000
*/


    public function testPrioritizeGreenAndUnconfirmedTXOs()
    {
        // receiving a transaction adds TXOs
        $txo_repository = app('App\Repositories\TXORepository');
        $txo_chooser    = app('App\Blockchain\Sender\TXOChooser');

        $float = function($i) { return CurrencyUtil::satoshisToValue($i); };

        // samples [1000, 2000, 3000, 50000]
        list($payment_address, $sample_txos) = $this->makeAddressAndSampleTXOs_2();

        // 1 confirmed TXO
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(900), $float(100), 0);
        $this->assertFound([0], $sample_txos, $chosen_txos);

        // Prioritize confirmed over unconfirmed
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(5900), $float(100), 0);
        $this->assertFound([3], $sample_txos, $chosen_txos);

        // Prioritize green over red
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(52900), $float(100), 0);
        $this->assertFound([3,1,0], $sample_txos, $chosen_txos);

    }
/*
3: 50000   CONFIRMED, red
2:  3000 UNCONFIRMED, red
1:  2000 UNCONFIRMED, green 
0:  1000   CONFIRMED, green
*/


    public function testChoosePrimeTXOs()
    {
        // receiving a transaction adds TXOs
        $txo_repository = app('App\Repositories\TXORepository');
        $txo_chooser    = app('App\Blockchain\Sender\TXOChooser');

        $float = function($i) { return CurrencyUtil::satoshisToValue($i); };

        list($payment_address, $sample_txos) = $this->makeAddressAndSampleTXOs();

        // 1 confirmed TXO
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(4900), $float(100), 0, TXOChooser::STRATEGY_PRIME);
        $this->assertFound([5], $sample_txos, $chosen_txos);

    }
    public function testChooseTXOsWithMinChange()
    {
        // receiving a transaction adds TXOs
        $txo_repository = app('App\Repositories\TXORepository');
        $txo_chooser    = app('App\Blockchain\Sender\TXOChooser');

        $float = function($i) { return CurrencyUtil::satoshisToValue($i); };

        list($payment_address, $sample_txos) = $this->makeAddressAndSampleTXOs();

        // 1 confirmed TXO
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(4899), $float(100), $float(5430));
        $this->assertFound([5,4], $sample_txos, $chosen_txos);

    }
/*
    6: 50000
    5:  7000
    4:  4000
    3:  3000
    2:  2000
    1:  2010
    0:  1000
*/

    public function testChooseTXOsWithNoChange()
    {
        // receiving a transaction adds TXOs
        $txo_repository = app('App\Repositories\TXORepository');
        $txo_chooser    = app('App\Blockchain\Sender\TXOChooser');

        $float = function($i) { return CurrencyUtil::satoshisToValue($i); };

        list($payment_address, $sample_txos) = $this->makeAddressAndSampleTXOs();

        // No change needed
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(10900), $float(100), $float(5430));
        $this->assertFound([5,4], $sample_txos, $chosen_txos);

        // sweep all UTXOs
        $chosen_txos = $txo_chooser->chooseUTXOs($payment_address, $float(10900), $float(100), $float(5430));
        $this->assertFound([5,4], $sample_txos, $chosen_txos);

    }
/*
    6: 50000
    5:  7000
    4:  4000
    3:  3000
    2:  2000
    1:  2010
    0:  1000
*/
    // ------------------------------------------------------------------------
    
    protected function TXOHelper() {
        if (!isset($this->txo_helper)) { $this->txo_helper = app('SampleTXOHelper'); }
        return $this->txo_helper;
    }

    protected function makeAddressAndSampleTXOs() {
        $payment_address_helper = app('PaymentAddressHelper');

        $txo_helper = $this->TXOHelper();
        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);
        $sample_txos = [];

        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 1000,  'n' => 0]);
        $sample_txos[1] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2010,  'n' => 1]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[2] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2000,  'n' => 0]);
        $sample_txos[3] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 3000,  'n' => 1]);
        $sample_txos[4] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 4000,  'n' => 2]);
        $sample_txos[5] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 7000,  'n' => 3]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[6] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 50000, 'n' => 2]);

        return [$payment_address, $sample_txos];
    }

    protected function assertFound($expected_offsets, $sample_txos, $chosen_txos) {
        $expected_txo_arrays = [];
        foreach($expected_offsets as $expected_offset) {
            $expected_txo_arrays[] = $sample_txos[$expected_offset]->toArray();
        }

        $actual_amounts = [];
        $chosen_txo_arrays = [];
        foreach($chosen_txos as $chosen_txo) {
            $chosen_txo_arrays[] = ($chosen_txo ? $chosen_txo->toArray() : null);
            $actual_amounts[] = $chosen_txo['amount'];
        }

        PHPUnit::assertEquals($expected_txo_arrays, $chosen_txo_arrays, "Did not find the expected offsets of ".json_encode($expected_offsets).'. Actual amounts were '.json_encode($actual_amounts));
    }



    protected function makeAddressAndSampleTXOs_2() {
        $payment_address_helper = app('PaymentAddressHelper');

        $txo_helper = $this->TXOHelper();
        $payment_address = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);
        $sample_txos = [];

        $txid = $txo_helper->nextTXID();
        $sample_txos[0] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 1000,  'n' => 0, 'type' => TXO::CONFIRMED,   'green' => 1]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[1] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 2000,  'n' => 0, 'type' => TXO::UNCONFIRMED, 'green' => 1]);
        $sample_txos[2] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 3000,  'n' => 1, 'type' => TXO::UNCONFIRMED, 'green' => 0]);
        $txid = $txo_helper->nextTXID();
        $sample_txos[3] = $txo_helper->createSampleTXO($payment_address, ['txid' => $txid, 'amount' => 50000, 'n' => 2, 'type' => TXO::CONFIRMED,   'green' => 0]);

        return [$payment_address, $sample_txos];
    }


}
