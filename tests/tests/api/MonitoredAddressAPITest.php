<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class MonitoredAddressAPITest extends TestCase {

    protected $useDatabase = true;

    public function testAPIAddMonitoredAddress()
    {
        // post using API
        $posted_vars = [
            'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
            'monitorType' => 'receive',
        ];
        $response = $this->call('POST', '/api/v1/monitor', $posted_vars);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Response was: ".$response->getContent());
        $created_address_from_api = json_decode($response->getContent(), 1);
        PHPUnit::assertNotEmpty($created_address_from_api);
        PHPUnit::assertEquals(
            ['id' => $created_address_from_api['id'], 'address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', 'monitorType' => 'receive', 'active' => true],
            $created_address_from_api
        );

    
        $monitored_address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $loaded_address_model = $monitored_address_repo->findByAddress('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD')->first();
        PHPUnit::assertNotEmpty($loaded_address_model);
        PHPUnit::assertEquals($created_address_from_api['id'], $loaded_address_model['uuid']);
        PHPUnit::assertEquals('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', $loaded_address_model['address']);
    }

    public function testAPIErrorsAddMonitoredAddress()
    {
        $this->runMonitorError(
            [
                'address'     => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD',
                'monitorType' => 'bad',
            ],
            'The selected monitor type is invalid'
        );

        $this->runMonitorError(
            [
                'address'     => 'xBAD123456789',
                'monitorType' => 'receive',
            ],
            'The address was invalid'
        );
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function runMonitorError($posted_vars, $expected_error) {
        $response = $this->call('POST', '/api/v1/monitor', $posted_vars);
        PHPUnit::assertEquals(400, $response->getStatusCode(), "Response was: ".$response->getContent());
        $response_data = json_decode($response->getContent(), true);
        PHPUnit::assertContains($expected_error, $response_data['errors'][0]);
    }

}
