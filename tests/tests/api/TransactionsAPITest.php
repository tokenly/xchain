<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionsAPITest extends TestCase {

    protected $useDatabase = true;


    public function testAPIListTransactions() {
        $api_tester = $this->getAPITester();

        // run a scenario first
        $this->app->make('\ScenarioRunner')->initMocks($this)->runScenarioByNumber(6);
    

        $address = 'RECIPIENT01';
        $response = $api_tester->callAPIWithAuthentication('GET', '/api/v1/transactions/'.$address);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Response was: ".$response->getContent());
        $loaded_transactions_from_api = json_decode($response->getContent(), 1);

        // sanity check
        PHPUnit::assertCount(1, $loaded_transactions_from_api);
        PHPUnit::assertEquals($address, $loaded_transactions_from_api[0]['notifiedAddress']);
    }

    public function testAPIListTransactionsUpdatesConfirmations() {
        $api_tester = $this->getAPITester();

        // run a scenario first
        $this->app->make('\ScenarioRunner')->initMocks($this)->runScenarioByNumber(6);
    
        // make the current block an arbitrary high number (99)
        $this->app->make('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH99', 'height' => 333099, 'parsed_block' => ['height' => 333099]]);

        $address = 'RECIPIENT01';
        $response = $api_tester->callAPIWithAuthentication('GET', '/api/v1/transactions/'.$address);
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
        return $this->app->make('APITester', [$this->app, '/api/v1/transactions', $this->app->make('App\Repositories\TransactionRepository')]);
    }


    protected function txHelper() {
        if (!isset($this->sample_tx_helper)) { $this->sample_tx_helper = $this->app->make('SampleTransactionsHelper'); }
        return $this->sample_tx_helper;
    }


}
