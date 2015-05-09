<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class AssetAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForBalances() {
        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth('GET', '/foo');
    }


    public function testAPIGetAsset() {
        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        $api_tester = $this->getAPITester();
        $response = json_decode($api_tester->callAPIWithAuthentication('GET', '/api/v1/assets/LTBCOIN')->getContent(), true);

        PHPUnit::assertEquals(true, $response['divisible']);

    }

    protected function getAPITester() {
        return $this->app->make('APITester', [$this->app, '/api/v1/assets', null]);
    }

}
