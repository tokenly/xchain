<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class NotificationRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddNotification()
    {
        $monitored_address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $created_address_model = $monitored_address_repo->create(['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', 'monitor_type' => 'receive']);

        // insert
        $notification_repo = $this->app->make('App\Repositories\NotificationRepository');
        $created_notification_model = $notification_repo->create($created_address_model);

        // load from repo
        $loaded_notification_model = $notification_repo->findById($created_notification_model['id']);
        PHPUnit::assertNotEmpty($loaded_notification_model);
        PHPUnit::assertNotEmpty($loaded_notification_model['uuid']);
        PHPUnit::assertEquals($created_notification_model['id'], $loaded_notification_model['id']);
        PHPUnit::assertEquals($created_address_model['id'], $loaded_notification_model['monitored_address_id']);
    }



}
