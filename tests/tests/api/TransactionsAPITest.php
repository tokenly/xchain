<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionsAPITest extends TestCase {

    protected $useDatabase = true;

    public function testRequireAuthForTransactions() {
        // run a scenario first
        $this->app->make('\ScenarioRunner')->init($this)->runScenarioByNumber(6);
    
        // find the address
        $monitored_address = $this->monitoredAddressByAddress('RECIPIENT01');

        $api_tester = $this->getAPITester();
        $api_tester->testRequireAuth('GET', '/'.$monitored_address['uuid']);
    }


    public function testAPIListTransactions() {
        // run a scenario first
        $this->app->make('\ScenarioRunner')->init($this)->runScenarioByNumber(6);

        // get the API tester (must be after running the scenario)
        $api_tester = $this->getAPITester();

        // find the address
        $monitored_address = $this->monitoredAddressByAddress('RECIPIENT01');

        $response = $api_tester->callAPIWithAuthentication('GET', '/api/v1/transactions/'.$monitored_address['uuid']);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Response was: ".$response->getContent());
        $loaded_transactions_from_api = json_decode($response->getContent(), 1);

        // sanity check
        PHPUnit::assertCount(1, $loaded_transactions_from_api);
        PHPUnit::assertEquals($monitored_address['address'], $loaded_transactions_from_api[0]['notifiedAddress']);
    }

    public function testBlockUpdatesConfirmationsForListTransactions() {
        // run a scenario first
        $this->app->make('\ScenarioRunner')->init($this)->runScenarioByNumber(6);
    
        // get the API tester (must be after running the scenario)
        $api_tester = $this->getAPITester();

        // make the current block an arbitrary high number (99)
        $this->app->make('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH99', 'height' => 333099, 'parsed_block' => ['height' => 333099]]);

        // find the address
        $monitored_address = $this->monitoredAddressByAddress('RECIPIENT01');

        $response = $api_tester->callAPIWithAuthentication('GET', '/api/v1/transactions/'.$monitored_address['uuid']);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Response was: ".$response->getContent());
        $loaded_transactions_from_api = json_decode($response->getContent(), 1);

        // check the confirmations count (100)
        PHPUnit::assertCount(1, $loaded_transactions_from_api);
        PHPUnit::assertEquals(100, $loaded_transactions_from_api[0]['confirmations']);
    }

    public function testAPITransactionNotFound() {
        $api_tester = $this->getAPITester();

        $response = $api_tester->callAPIWithAuthentication('GET', '/api/v1/transactions/foo');
        PHPUnit::assertEquals(404, $response->getStatusCode(), "Response was: ".$response->getContent());
    }



    ////////////////////////////////////////////////////////////////////////
    
    protected function getAPITester() {
        $api_tester =  $this->app->make('APITester', [$this->app, '/api/v1/transactions', $this->app->make('App\Repositories\TransactionRepository')]);
        $api_tester->ensureAuthenticatedUser();
        return $api_tester;
    }



    protected function txHelper() {
        if (!isset($this->sample_tx_helper)) { $this->sample_tx_helper = $this->app->make('SampleTransactionsHelper'); }
        return $this->sample_tx_helper;
    }

    protected function monitoredAddressByAddress() {
        $address_repo = $this->app->make('App\Repositories\MonitoredAddressRepository');
        $monitored_address = $address_repo->findByAddress('RECIPIENT01')->first();
        return $monitored_address;

    }



}
