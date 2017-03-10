<?php

use App\Blockchain\Sender\FeeCache;

/**
*  FeePriorityHelper
*/
class FeePriorityHelper
{

    const FEE_SATOSHIS_PER_BYTE_LOW     = 5;
    const FEE_SATOSHIS_PER_BYTE_LOWMED  = 84;
    const FEE_SATOSHIS_PER_BYTE_MED     = 118;
    const FEE_SATOSHIS_PER_BYTE_MEDHIGH = 151;
    const FEE_SATOSHIS_PER_BYTE_HIGH    = 201;

    public function __construct() {
    }

    public function mockFeeCache() {
        $fee_cache_mock = Mockery::mock(FeeCache::class)->makePartial();
        $fee_cache_mock->shouldReceive('loadFromAPI')->andReturn($this->sampleFeesList());
        app()->instance(FeeCache::class, $fee_cache_mock);
        return $fee_cache_mock;

    }

    public function sampleFeesList() {
        return [
            0 => [
                'minFee' => 0,
                'maxFee' => 0,
                'minDelay' => 16,
                'maxDelay' => 10000,
            ],
            1 => [
                'minFee' => 5,
                'maxFee' => 20,
                'minDelay' => 4,
                'maxDelay' => 113,
            ],
            2 => [
                'minFee' => 21,
                'maxFee' => 40,
                'minDelay' => 4,
                'maxDelay' => 99,
            ],
            3 => [
                'minFee' => 41,
                'maxFee' => 60,
                'minDelay' => 4,
                'maxDelay' => 55,
            ],
            4 => [
                'minFee' => 61,
                'maxFee' => 80,
                'minDelay' => 3,
                'maxDelay' => 24,
            ],
            5 => [
                'minFee' => 81,
                'maxFee' => 100,
                'minDelay' => 1,
                'maxDelay' => 16,
            ],
            6 => [
                'minFee' => 101,
                'maxFee' => 120,
                'minDelay' => 1,
                'maxDelay' => 10,
            ],
            7 => [
                'minFee' => 121,
                'maxFee' => 140,
                'minDelay' => 0,
                'maxDelay' => 4,
            ],
            8 => [
                'minFee' => 141,
                'maxFee' => 160,
                'minDelay' => 0,
                'maxDelay' => 3,
            ],
            9 => [
                'minFee' => 161,
                'maxFee' => 180,
                'minDelay' => 0,
                'maxDelay' => 1,
            ],
            10 => [
                'minFee' => 181,
                'maxFee' => 200,
                'minDelay' => 0,
                'maxDelay' => 1,
            ],
            11 => [
                'minFee' => 201,
                'maxFee' => 220,
                'minDelay' => 0,
                'maxDelay' => 0,
            ],
            12 => [
                'minFee' => 221,
                'maxFee' => 240,
                'minDelay' => 0,
                'maxDelay' => 0,
            ],
            13 => [
                'minFee' => 241,
                'maxFee' => 260,
                'minDelay' => 0,
                'maxDelay' => 0,
            ],
            14 => [
                'minFee' => 261,
                'maxFee' => 37990,
                'minDelay' => 0,
                'maxDelay' => 0,
            ],
        ];
    }

    
}