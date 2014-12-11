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

        $mock = $test_case->getMockBuilder('\Tokenly\Insight\Client')->disableOriginalConstructor()->getMock();
        $mock->method('getTransaction')->will($test_case->returnCallback(function($txid) {
            $data = $this->loadAPIFixture('_tx_'.$txid.'.json');
            return $data;
        })); 

        $app->bind('Tokenly\Insight\Client', function() use ($mock) {
            return $mock;
        });
    }




    public function loadAPIFixture($filename) {
        $filepath = base_path().'/tests/fixtures/api/'.$filename;
        return json_decode(file_get_contents($filepath), true);
    }


}