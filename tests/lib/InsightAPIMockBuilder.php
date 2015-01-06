<?php


/**
*  InsightAPIMockBuilder
*/
class InsightAPIMockBuilder
{

    function __construct() {

    }

    public function installMockInsightClient($app, $test_case) {
        // $old_client = $app->make('Tokenly\Insight\Client');
        $mock_calls = new \ArrayObject(['insight' => []]);

        $mock = $test_case->getMockBuilder('\Tokenly\Insight\Client')->disableOriginalConstructor()->getMock();
        $mock->method('getTransaction')->will($test_case->returnCallback(function($txid) use ($mock_calls) {
            $data = $this->loadAPIFixture('_tx_'.$txid.'.json');

            $mock_calls['insight'][] = [
                'method'   => 'getTransaction',
                'args'     => [$txid],
                'response' => $data,
            ];

            return $data;
        })); 

        $mock->method('getBlock')->will($test_case->returnCallback(function($hash) use ($mock_calls) {
            $data = $this->loadAPIFixture('_block_'.$hash.'.json');

            $mock_calls['insight'][] = [
                'method'   => 'getBlock',
                'args'     => [$hash],
                'response' => $data,
            ];

            return $data;
        })); 

        $mock->method('getUnspentTransactions')->will($test_case->returnCallback(function($address) use ($mock_calls) {
            if ($this->apiFixtureExists('_utxos_'.$address.'.json')) {
                $data = $this->loadAPIFixture('_utxos_'.$address.'.json');
            } else {
                $data = $this->loadAPIFixture('_utxos_sample.json');
            }

            $mock_calls['insight'][] = [
                'method'   => 'getUnspentTransactions',
                'args'     => [$address],
                'response' => $data,
            ];

            return $data;
        })); 

        $app->bind('Tokenly\Insight\Client', function() use ($mock) {
            return $mock;
        });

        return $mock_calls;
    }




    public function loadAPIFixture($filename) {
        $filepath = base_path().'/tests/fixtures/api/'.$filename;
        return json_decode(file_get_contents($filepath), true);
    }

    public function apiFixtureExists($filename) {
        $filepath = base_path().'/tests/fixtures/api/'.$filename;
        return file_exists($filepath);
    }


}