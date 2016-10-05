<?php


class CopayClientMockHelper  {

    function __construct() {
    }


    public function mockCopayClient() {
        $mock = Mockery::mock('Tokenly\CopayClient\CopayClient');
        app()->instance('Tokenly\CopayClient\CopayClient', $mock);
        return $mock;
    }

    public function mockTokenGenerator() {
        $mock = Mockery::mock('Tokenly\TokenGenerator\TokenGenerator');
        $mock->shouldReceive('generateToken')->andReturn('A00000000000000000000000000001');
        app()->instance('Tokenly\TokenGenerator\TokenGenerator', $mock);
        return $mock;
    }
}
