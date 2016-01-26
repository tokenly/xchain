<?php

use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class XChainErrorCounterTest extends TestCase {

    public function testXChainErrorCounter() {
        PHPUnit::assertEquals(0, app('XChainErrorCounter')->getErrorCount());
        app('XChainErrorCounter')->incrementErrorCount();
        PHPUnit::assertEquals(1, app('XChainErrorCounter')->getErrorCount());
        app('XChainErrorCounter')->incrementErrorCount();
        PHPUnit::assertEquals(2, app('XChainErrorCounter')->getErrorCount());

        PHPUnit::assertFalse(app('XChainErrorCounter')->maxErrorCountReached());
        app('XChainErrorCounter')->incrementErrorCount(10);
        PHPUnit::assertTrue(app('XChainErrorCounter')->maxErrorCountReached());
    }


}
