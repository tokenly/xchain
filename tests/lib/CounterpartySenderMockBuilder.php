<?php


/**
*  CounterpartySenderMockBuilder
*/
class CounterpartySenderMockBuilder
{

    function __construct() {

    }

    public function installMockCounterpartySenderDependencies($app, $test_case) {
        $mock_calls = new \ArrayObject(['xcpd' => [], 'btcd' => [], 'insight' => []]);

        $this->installMockXCPDClient($app, $test_case, $mock_calls);
        $this->installMockBitcoindClient($app, $test_case, $mock_calls);

        $insight_mock_calls = $app->make('InsightAPIMockBuilder')->installMockInsightClient($app, $test_case);
        $mock_calls['insight'] = $insight_mock_calls;

        return $mock_calls;
    }

    public function installMockXCPDClient($app, $test_case, $mock_calls) {
        $mock = $test_case->getMockBuilder('Tokenly\XCPDClient\Client')->disableOriginalConstructor()->getMock();

        $mock->method('__call')->will($test_case->returnCallback(function($name, $arguments) use ($mock_calls) {
            $vars = $arguments[0];
            $transaction_hex = '7777777777777'.hash('sha256', json_encode($vars));
            $mock_calls['xcpd'][] = [
                'method'   => $name,
                'args'     => $arguments,
                'response' => $transaction_hex,
            ];

            // return a mock get_balances response
            if ($name == 'get_balances') {
                return [
                    [
                        'asset'    => 'FOOCOIN',
                        'quantity' => 100,
                    ]
                ];
            }
            // return a mock get_asset_info response
            //   will accept any asset, but returns the details below
            if ($name == 'get_asset_info') {
                $asset = $arguments[0]['assets'][0];
                return [
                    0 => [
                        'issuer'      => "1Hso4cqKAyx9bsan8b5nbPqMTNNce8ZDto",
                        'description' => "Crypto-Rewards Program http://ltbcoin.com",
                        'owner'       => "1Hso4cqKAyx9bsan8b5nbPqMTNNce8ZDto",
                        'locked'      => false,
                        'asset'       => "$asset",
                        'supply'      => 29833802457990000,
                        'divisible'   => true
                    ],
                ];
            }

            if ($name == 'get_sends') {
                $txid = $asset = $arguments[0]['filters']['value'];
                $filepath = base_path()."/tests/fixtures/sends/{$txid}.json";
                if (!file_exists($filepath)) { throw new Exception("Send fixture not found for $txid", 1); }
                $send = json_decode(file_get_contents($filepath), true);
                return [$send];
            }

            return $transaction_hex;
        })); 
        $app->bind('Tokenly\XCPDClient\Client', function() use ($mock) {
            return $mock;
        });
    }

    public function installMockBitcoindClient($app, $test_case, $mock_calls) {
        $mock = $test_case->getMockBuilder('Nbobtc\Bitcoind\Bitcoind')->disableOriginalConstructor()->getMock();
        
        $mock->method('createrawtransaction')->will($test_case->returnCallback(function($inputs, $destinations)  use ($mock_calls) {
            $transaction_hex = '5555555555555'.hash('sha256', json_encode([$inputs, $destinations]));
            $mock_calls['btcd'][] = [
                'method'   => 'createrawtransaction',
                'args'     => [$inputs, $destinations],
                'response' => $transaction_hex,
            ];
            return $transaction_hex;
        })); 
        
        $mock->method('signrawtransaction')->will($test_case->returnCallback(function($hex, $txinfo=[], $keys=[], $sighashtype='ALL')  use ($mock_calls) {
            $transaction_hex = '5555555555555'.hash('sha256', json_encode([$hex, $txinfo, $keys, $sighashtype]));
            $out = (object) [
                'hex'      => $transaction_hex,
                'complete' => 1,
            ];
            $mock_calls['btcd'][] = [
                'method'   => 'signrawtransaction',
                'args'     => [$hex, $txinfo, $keys, $sighashtype],
                'response' => $out,
            ];
            return $out;
        })); 
        
        $mock->method('sendrawtransaction')->will($test_case->returnCallback(function($hex, $allowhighfees=false)  use ($mock_calls) {
            $txid = hash('sha256', json_encode([$hex, $allowhighfees]));
            $mock_calls['btcd'][] = [
                'method'   => 'sendrawtransaction',
                'args'     => [$hex, $allowhighfees],
                'response' => $txid,
            ];
            return $txid;
        })); 

        $app->bind('Nbobtc\Bitcoind\Bitcoind', function() use ($mock) {
            return $mock;
        });
    }


}