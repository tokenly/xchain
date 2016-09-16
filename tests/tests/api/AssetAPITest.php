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

    public function testAPIGetMultipleAssets() {
        $mock_cache = Mockery::mock('Tokenly\CounterpartyAssetInfoCache\Cache');
        $mock_cache->shouldReceive('getMultiple')->with(['XFOO','XBAR','XBAZ'])->once()->andReturn([
            ['asset' => 'XFOO', 'status' => 'valid',],
            ['asset' => 'XBAR', 'status' => 'valid',],
            ['asset' => 'XBAZ', 'status' => 'valid',],
        ]);
        app()->bind('Tokenly\CounterpartyAssetInfoCache\Cache', function() use ($mock_cache) {
            return $mock_cache;
        });

        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        // // install the counterparty client mock
        // $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();


        // check error
        $response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/assets', ['assets' => 'ABAD,XFOO'], 400);
        PHPUnit::assertContains('invalid', $response['message']);

        $response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/assets', ['assets' => 'XFOO,XBAR,XBAZ'], 200);

        PHPUnit::assertCount(3, $response);

        PHPUnit::assertEquals('XFOO', $response[0]['asset']);
        PHPUnit::assertEquals('valid', $response[0]['status']);
        PHPUnit::assertEquals('XBAR', $response[1]['asset']);
        PHPUnit::assertEquals('valid', $response[1]['status']);
        PHPUnit::assertEquals('XBAZ', $response[2]['asset']);
        PHPUnit::assertEquals('valid', $response[2]['status']);

        Mockery::close();

    }

    public function testAPISingleAssetNameGetMultipleAssets() {
        $mock_cache = Mockery::mock('Tokenly\CounterpartyAssetInfoCache\Cache');
        $mock_cache->shouldReceive('getMultiple')->with(['XFOO'])->once()->andReturn([
            ['asset' => 'XFOO', 'status' => 'valid',],
        ]);
        app()->bind('Tokenly\CounterpartyAssetInfoCache\Cache', function() use ($mock_cache) {
            return $mock_cache;
        });

        // sample user for Auth
        $sample_user = $this->app->make('\UserHelper')->createSampleUser();

        // // install the counterparty client mock
        // $mock_calls = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $api_tester = $this->getAPITester();


        $response = $api_tester->callAPIWithAuthenticationAndReturnJSONContent('GET', '/api/v1/assets', ['assets' => 'XFOO'], 200);

        PHPUnit::assertCount(1, $response);

        PHPUnit::assertEquals('XFOO', $response[0]['asset']);
        PHPUnit::assertEquals('valid', $response[0]['status']);

        Mockery::close();

    }

    protected function getAPITester() {
        return $this->app->make('SimpleAPITester', [$this->app, '/api/v1/assets', null]);
    }

}
