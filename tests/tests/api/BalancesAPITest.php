<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class BalancesAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForBalances() {
        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth('GET', '/foo');
    }


    public function testAPIGetBalance() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        // mock the xcp sender
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();
        $api_tester->callAPIWithAuthentication('GET', '/api/v1/balances/1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD');

        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $mock_calls['xcpd'][0]['args'][0]['filters']['value']);
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        return $this->app->make('APITester', [$this->app, '/api/v1/balances', null]);
    }


}
