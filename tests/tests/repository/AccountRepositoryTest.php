<?php

use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class AccountRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAccountRespository()
    {
        $helper = $this->createRepositoryTestHelper();

        $helper->testLoad();
        $helper->cleanup()->testUpdate(['name' => 'Updated Account']);
        $helper->cleanup()->testUpdate(['meta' => ['bar' => 'baz2']]);
        $helper->cleanup()->testDelete();
        $helper->cleanup()->testFindAll();
    }


    protected function createRepositoryTestHelper() {
        $create_model_fn = function() {
            $monitored_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutDefaultAccount();
            return app('AccountHelper')->newSampleAccount($monitored_address);
        };
        $helper = new RepositoryTestHelper($create_model_fn, $this->app->make('App\Repositories\AccountRepository'));
        return $helper;
    }

}
