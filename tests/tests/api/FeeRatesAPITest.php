<?php

use App\Blockchain\Sender\FeePriority;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class FeeRatesAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIFeeRates()
    {
        // mock the sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $api_tester = $this->getAPITester();

        $fee_rates = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/feerates');
        PHPUnit::assertEquals([
            'low'        => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOW),
            'lowSat'     => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOW,
            'medlow'     => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOWMED),
            'medlowSat'  => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_LOWMED,
            'medium'     => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MED),
            'mediumSat'  => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MED,
            'medhigh'    => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MEDHIGH),
            'medhighSat' => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_MEDHIGH,
            'high'       => CurrencyUtil::satoshisToValue(FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_HIGH),
            'highSat'    => FeePriorityHelper::FEE_SATOSHIS_PER_BYTE_HIGH,
        ], $fee_rates);
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester($url='/api/v1/estimatefee') {
        $api_tester =  $this->app->make('SimpleAPITester', [$this->app, $url, $this->app->make('App\Repositories\SendRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }

}
