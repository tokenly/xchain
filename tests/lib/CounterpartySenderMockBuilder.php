<?php


/**
*  CounterpartySenderMockBuilder
*/
class CounterpartySenderMockBuilder
{

    function __construct() {

    }

    public function installMockCounterpartySenderDependencies($app, $test_case) {
        $this->installMockXCPDClient($app, $test_case);
        $this->installMockBitcoindClient($app, $test_case);
    }

    public function installMockXCPDClient($app, $test_case) {
        $mock = $test_case->getMockBuilder('Tokenly\XCPDClient\Client')->disableOriginalConstructor()->getMock();
        $mock->method('create_send')->will($test_case->returnCallback(function($vars) {
            $transaction_hex = '7777777777777'.hash('sha256', json_encode($vars));
            return $transaction_hex;
        })); 
        $app->bind('Tokenly\XCPDClient\Client', function() use ($mock) {
            return $mock;
        });
    }

    public function installMockBitcoindClient($app, $test_case) {
        $mock = $test_case->getMockBuilder('Nbobtc\Bitcoind\Bitcoind')->disableOriginalConstructor()->getMock();
        
        $mock->method('signrawtransaction')->will($test_case->returnCallback(function($hex, $txinfo=[], $keys=[], $sighashtype='ALL') {
            $transaction_hex = '5555555555555'.hash('sha256', json_encode([$hex, $txinfo, $keys, $sighashtype]));
            return (object) [
                'hex' => $transaction_hex,
            ];
        })); 
        
        $mock->method('sendrawtransaction')->will($test_case->returnCallback(function($hex, $allowhighfees=false) {
            $txid = hash('sha256', json_encode([$hex, $allowhighfees]));
            return $txid;
        })); 

        $app->bind('Nbobtc\Bitcoind\Bitcoind', function() use ($mock) {
            return $mock;
        });
    }


}