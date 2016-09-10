<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class AssetAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIGetAsset() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        // install the counterparty client mock
        $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();
        $response = json_decode($api_tester->callAPIWithAuthentication('GET', '/api/v1/assets/LTBCOIN')->getContent(), true);

        PHPUnit::assertEquals(true, $response['divisible']);
        PHPUnit::assertEquals('0000000000000000000000000000000000000000000000000000000022222222', $response['tx_hash']);
        PHPUnit::assertEquals('valid', $response['status']);

    }

    protected function getAPITester() {
        return $this->app->make('SimpleAPITester', [$this->app, '/api/v1/assets', null]);
    }

}
