<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class MonitoredAddressRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddAddress()
    {
        // insert
        $monitored_address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $created_address_model = $monitored_address_repo->create(['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', 'monitor_type' => 'receive']);

        // load from repo
        $loaded_address_models = $monitored_address_repo->findByAddress('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD');
        $loaded_address_model = $loaded_address_models->first();
        PHPUnit::assertNotEmpty($loaded_address_model);
        PHPUnit::assertEquals(1, $loaded_address_models->count());
        PHPUnit::assertEquals($created_address_model['id'], $loaded_address_model['id']);
        PHPUnit::assertEquals($created_address_model['address'], $loaded_address_model['address']);
    }


    public function testFindMany()
    {
        // insert
        $monitored_address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $monitored_address_repo->create(['address' => '1recipient111111111111111111111111', 'monitor_type' => 'receive']);
        $monitored_address_repo->create(['address' => '1recipient222222222222222222222222', 'monitor_type' => 'receive']);
        $monitored_address_repo->create(['address' => '1recipient333333333333333333333333', 'monitor_type' => 'receive']);
        $monitored_address_repo->create(['address' => '1recipient444444444444444444444444', 'monitor_type' => 'receive']);

        // load from repo
        $loaded_address_models = $monitored_address_repo->findByAddresses(['1recipient222222222222222222222222','1recipient333333333333333333333333']);
        PHPUnit::assertEquals(2, $loaded_address_models->count());
    }


}
