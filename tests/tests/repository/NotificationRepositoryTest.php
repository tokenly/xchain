<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class NotificationRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddNotification()
    {
        $monitored_address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $monitored_address_helper = $this->app->make('\MonitoredAddressHelper');
        $created_address_model = $monitored_address_repo->create($monitored_address_helper->sampleDBVars());
        $created_event_monitor = app('EventMonitorHelper')->newSampleEventMonitor();
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');

        // insert
        $notification_helper = $this->app->make('\NotificationHelper');
        $created_notification_model = $notification_helper->createSampleNotification($created_address_model, [], $block);

        // load from repo
        $notification_repo = $this->app->make('App\Repositories\NotificationRepository');
        $loaded_notification_model = $notification_repo->findById($created_notification_model['id']);
        PHPUnit::assertNotEmpty($loaded_notification_model);
        PHPUnit::assertNotEmpty($loaded_notification_model['uuid']);
        PHPUnit::assertEquals($created_notification_model['id'], $loaded_notification_model['id']);
        PHPUnit::assertEquals($created_address_model['id'], $loaded_notification_model['monitored_address_id']);
        PHPUnit::assertEquals('new', $loaded_notification_model['status']);


        // load by address
        $loaded_notification_models = $notification_repo->findByMonitoredAddressId($created_address_model['id']);
        PHPUnit::assertNotEmpty($loaded_notification_models);
        $loaded_notification_model = $loaded_notification_models[0];
        PHPUnit::assertNotEmpty($loaded_notification_model);
        PHPUnit::assertNotEmpty($loaded_notification_model['uuid']);
        PHPUnit::assertEquals($created_notification_model['id'], $loaded_notification_model['id']);

        // load by event_monitor
        $created_notification_model = $notification_helper->createSampleNotificationForEventMonitor($created_event_monitor, [], $block);
        $loaded_notification_models = $notification_repo->findByEventMonitorId($created_event_monitor['id']);
        PHPUnit::assertNotEmpty($loaded_notification_models);
        $loaded_notification_model = $loaded_notification_models[0];
        PHPUnit::assertNotEmpty($loaded_notification_model);
        PHPUnit::assertNotEmpty($loaded_notification_model['uuid']);
        PHPUnit::assertEquals($created_notification_model['id'], $loaded_notification_model['id']);
    }



}
