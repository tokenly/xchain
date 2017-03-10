<?php

use App\Blockchain\Sender\FeePriority;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class EstimateFeeAPITest extends TestCase {

    protected $useRealSQLiteDatabase = true;


    public function testAPIErrorsEstimateFee()
    {
        // mock the xcp sender
        $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        $api_tester->testAddErrors([

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'quantity'    => 1,
                ],
                'expectedErrorString' => 'asset field is required.',
            ],

            [
                'postVars' => [
                    'destination' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                    'asset'       => 'FOO',
                    'quantity'    => 0,
                ],
                'expectedErrorString' => 'quantity is invalid',
            ],

        ], '/'.$payment_address['uuid']);
    }

    public function testAPICalculateFee()
    {
        // mock the sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);

        $api_tester = $this->getAPITester();

        // BTC send
        $posted_vars = $this->sendHelper()->samplePostVars();
        $posted_vars['quantity'] = 0.025;
        $posted_vars['asset'] = 'BTC';

        $fees_info = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/estimatefee/'.$payment_address['uuid'], $posted_vars);

        $bytes = $fees_info['size'];
        PHPUnit::assertGreaterThanOrEqual(225, $bytes);
        PHPUnit::assertLessThan(230, $bytes);
        PHPUnit::assertEquals([
            'size' => $bytes,
            'fees' => [
                'low'     => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOW * $bytes),
                'lowSat'  => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOW * $bytes,
                'lowmed'     => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOWMED * $bytes),
                'lowmedSat'  => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOWMED * $bytes,
                'med'     => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MED * $bytes),
                'medSat'  => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MED * $bytes,
                'medhigh'    => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MEDHIGH * $bytes),
                'medhighSat' => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MEDHIGH * $bytes,
                'high'    => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes),
                'highSat' => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes,
            ],
        ], $fees_info);

        // Counterparty send
        $posted_vars = $this->sendHelper()->samplePostVars();
        $posted_vars['quantity'] = 10;
        $posted_vars['asset'] = 'TOKENLY';

        $fees_info = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('POST', '/api/v1/estimatefee/'.$payment_address['uuid'], $posted_vars);
        $bytes = $fees_info['size'];
        PHPUnit::assertGreaterThanOrEqual(264, $bytes);
        PHPUnit::assertLessThan(270, $bytes);
        PHPUnit::assertEquals([
            'size' => $bytes,
            'fees' => [
                'low'        => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOW * $bytes),
                'lowSat'     => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOW * $bytes,
                'lowmed'     => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOWMED * $bytes),
                'lowmedSat'  => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOWMED * $bytes,
                'med'        => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MED * $bytes),
                'medSat'     => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MED * $bytes,
                'medhigh'    => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MEDHIGH * $bytes),
                'medhighSat' => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MEDHIGH * $bytes,
                'high'       => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes),
                'highSat'    => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_HIGH * $bytes,
            ],
        ], $fees_info);
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester($url='/api/v1/estimatefee') {
        $api_tester =  $this->app->make('SimpleAPITester', [$this->app, $url, $this->app->make('App\Repositories\SendRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }



    protected function sendHelper() {
        if (!isset($this->sample_sends_helper)) { $this->sample_sends_helper = $this->app->make('SampleSendsHelper'); }
        return $this->sample_sends_helper;
    }


}
