<?php

use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class APICallRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAPICallRespository()
    {
        $helper = $this->createRepositoryTestHelper();

        $helper->testLoad();
        $helper->cleanup()->testDelete();
        $helper->cleanup()->testFindAll();
    }


    protected function createRepositoryTestHelper() {
        $create_model_fn = function() {
            return app('APICallHelper')->newSampleAPICall();
        };
        $helper = new RepositoryTestHelper($create_model_fn, $this->app->make('App\Repositories\APICallRepository'));
        return $helper;
    }

}
