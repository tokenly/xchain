<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class PaymentAddressRepositoryTest extends TestCase {

    protected $useDatabase = true;


    public function testFindPaymentAddressesOnly()
    {
        // insert
        $payment_address_helper = $this->app->make('\PaymentAddressHelper');
        $payment_address_repo = $this->app->make('App\Repositories\PaymentAddressRepository');
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment111111111111111111111111']));
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment222222222222222222222222']));
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment333333333333333333333333']));
        $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment444444444444444444444444']));

        // load from repo
        $addresses = $payment_address_repo->findAllAddresses();
        PHPUnit::assertEquals(['1payment111111111111111111111111','1payment222222222222222222222222','1payment333333333333333333333333','1payment444444444444444444444444',], $addresses);
    }

    public function testDestroyPaymentAddressMovesToArchive()
    {
        // insert
        $payment_address_helper = $this->app->make('\PaymentAddressHelper');
        $payment_address_repo = $this->app->make('App\Repositories\PaymentAddressRepository');
        $payment_address_archive_repo = $this->app->make('App\Repositories\PaymentAddressArchiveRepository');
        $address = $payment_address_repo->create($payment_address_helper->sampleVars(['address' => '1payment111111111111111111111111']));

        // destroy the address
        $payment_address_repo->delete($address);


        // ensure it was deleted
        $all_addresses = $payment_address_repo->findAll();
        PHPUnit::assertCount(0, $all_addresses);


        // load from the archive repository
        $all_archived_addresses = $payment_address_archive_repo->findAll();
        PHPUnit::assertCount(1, $all_archived_addresses);


    }


    public function testDestroyPaymentAddressClearsArtifacts()
    {
        // insert
        $payment_address_helper = $this->app->make('\PaymentAddressHelper');
        $user_helper = $this->app->make('UserHelper');
        $monitored_address_helper = $this->app->make('MonitoredAddressHelper');
        $payment_address_repo = $this->app->make('App\Repositories\PaymentAddressRepository');
        $payment_address_archive_repo = $this->app->make('App\Repositories\PaymentAddressArchiveRepository');
        $ledger_entry_repository = $this->app->make('App\Repositories\LedgerEntryRepository');
        $account_repository = $this->app->make('App\Repositories\AccountRepository');
        $monitor_respository = $this->app->make('App\Repositories\MonitoredAddressRepository');

        // create sample address
        $address = $payment_address_helper->createSamplePaymentAddress();

        // create monitors
        $mon1 = $monitored_address_helper->createSampleMonitoredAddress(null, ['address' => $address['address'], 'monitorType' => 'receive']);
        $mon2 = $monitored_address_helper->createSampleMonitoredAddress(null, ['address' => $address['address'], 'monitorType' => 'send']);

        // destroy the address
        Auth::setUser($user_helper->getSampleUser());
        $controller = app('App\Http\Controllers\API\PaymentAddressController');
        $controller->destroy(app('Tokenly\LaravelApiProvider\Helpers\APIControllerHelper'), $payment_address_repo, $monitor_respository, $account_repository, $ledger_entry_repository, $address['uuid']);


        // ensure it was deleted
        $all_addresses = $payment_address_repo->findAll();
        PHPUnit::assertCount(0, $all_addresses);


        // load from the archive repository
        $all_archived_addresses = $payment_address_archive_repo->findAll();
        PHPUnit::assertCount(1, $all_archived_addresses);

        // make sure accounts and ledger entries are clear
        PHPUnit::assertCount(0, $account_repository->findAll());
        PHPUnit::assertCount(0, $ledger_entry_repository->findAll());

        // make sure monitors are clear
        PHPUnit::assertCount(0, $monitor_respository->findAll());


    }


}
