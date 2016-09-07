<?php

use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class EventMonitorRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testEventMonitorRespository()
    {
        $helper = $this->createRepositoryTestHelper();

        $helper->testLoad();
        $helper->cleanup()->testDelete();
        $helper->cleanup()->testFindAll();
    }

    public function testFindEventMonitorByEventType()
    {
        $sample_user = app('UserHelper')->createSampleUser();
        $helper = app('EventMonitorHelper');
        $created_monitors = [
            $helper->newSampleEventMonitor($sample_user),
            $helper->newSampleEventMonitor($sample_user, ['monitorType' => 'issuance']),
            $helper->newSampleEventMonitor($sample_user, ['monitorType' => 'issuance']),
        ];

        $repository = app('App\Repositories\EventMonitorRepository');
        $block_monitors = $repository->findByEventType('block');
        // echo "\$block_monitors: ".json_encode(get_class($block_monitors), 192)."\n";
        PHPUnit::assertCount(1, $block_monitors);
        PHPUnit::assertEquals($created_monitors[0]['id'], $block_monitors[0]['id']);
        $block_monitors = $repository->findByEventType('issuance');
        PHPUnit::assertCount(2, $block_monitors);

    }


    protected function createRepositoryTestHelper() {
        $create_model_fn = function() {
            return app('EventMonitorHelper')->newSampleEventMonitor();
        };
        $helper = new RepositoryTestHelper($create_model_fn, app('App\Repositories\EventMonitorRepository'));
        return $helper;
    }

}
