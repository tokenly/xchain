<?php

use App\Blockchain\Sender\FeePriority;
use App\Models\TXO;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class FeePriorityTest extends TestCase {

    public function testLookupFees()
    {
        app('FeePriorityHelper')->mockFeeCache();
        $fee_priority = app(FeePriority::class);

        // 0 block delay
        PHPUnit::assertEquals(201, $fee_priority->getSatoshisPerByte('1block'));

        // 1 block delay
        PHPUnit::assertEquals(161, $fee_priority->getSatoshisPerByte('2blocks'));

        // 2 block delay
        PHPUnit::assertEquals(151, $fee_priority->getSatoshisPerByte('3blocks'));

        // 2.5 block delay
        PHPUnit::assertEquals(146, $fee_priority->getSatoshisPerByte('3.5 blocks'));

        // 3 block delay
        PHPUnit::assertEquals(141, $fee_priority->getSatoshisPerByte('4blocks'));

        // 4 block delay
        PHPUnit::assertEquals(121, $fee_priority->getSatoshisPerByte('5 blocks'));

        // 5-9 block delay
        PHPUnit::assertEquals(118, $fee_priority->getSatoshisPerByte('6 blocks'));
        PHPUnit::assertEquals(114, $fee_priority->getSatoshisPerByte('7 blocks'));
        PHPUnit::assertEquals(111, $fee_priority->getSatoshisPerByte('8 blocks'));
        PHPUnit::assertEquals(108, $fee_priority->getSatoshisPerByte('9 blocks'));
        PHPUnit::assertEquals(104, $fee_priority->getSatoshisPerByte('10 blocks'));

        // 10 block delay
        PHPUnit::assertEquals(101, $fee_priority->getSatoshisPerByte('11 blocks'));

        // 120 block delay
        PHPUnit::assertEquals(5, $fee_priority->getSatoshisPerByte('120 blocks'));

        // words
        PHPUnit::assertEquals(5, $fee_priority->getSatoshisPerByte('low'));
        PHPUnit::assertEquals(84, $fee_priority->getSatoshisPerByte('medlow'));
        PHPUnit::assertEquals(118, $fee_priority->getSatoshisPerByte('medium'));
        PHPUnit::assertEquals(151, $fee_priority->getSatoshisPerByte('medhigh'));
        PHPUnit::assertEquals(201, $fee_priority->getSatoshisPerByte('high'));

        // numbers
        PHPUnit::assertEquals(12, $fee_priority->getSatoshisPerByte('12'));
        PHPUnit::assertEquals(20, $fee_priority->getSatoshisPerByte('20'));
        PHPUnit::assertEquals(202, $fee_priority->getSatoshisPerByte('202'));
        PHPUnit::assertEquals(500, $fee_priority->getSatoshisPerByte('500'));

    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Invalid numeric fee
     */
    public function testValidationOfLookupFees_1() {
        app('FeePriorityHelper')->mockFeeCache();
        $fee_priority = app(FeePriority::class);

        $fee_priority->getSatoshisPerByte('0');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Invalid numeric fee
     */
    public function testValidationOfLookupFees_2() {
        app('FeePriorityHelper')->mockFeeCache();
        $fee_priority = app(FeePriority::class);

        $fee_priority->getSatoshisPerByte('-1');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Unknown fee string
     */
    public function testValidationOfLookupFees_3() {
        app('FeePriorityHelper')->mockFeeCache();
        $fee_priority = app(FeePriority::class);

        $fee_priority->getSatoshisPerByte('bad');
    }

}
