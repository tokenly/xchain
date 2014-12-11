<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class XChainScenariosTest extends TestCase {

    protected $useDatabase = true;


    public function testSingleXChainScenario() {
        $scenario_number = getenv('SCENARIO');

        if ($scenario_number !== false) {
            $this->runScenario($scenario_number);
        }
        // echo "\$scenario_number_vars:\n".json_encode($scenario_number_vars, 192)."\n";
    } 

    public function testAllXChainScenarios() {
        // do all state tests in directory
        $scenario_number_count = count(glob(base_path().'/tests/fixtures/scenarios/*.yml'));
        PHPUnit::assertGreaterThan(0, $scenario_number_count);
        for ($i=1; $i <= $scenario_number_count; $i++) { 
            // clear the db
            if ($i > 1) {
                $this->resetForScenario();
            }

            $this->runScenario($i);
        }
    }


    protected function runScenario($scenario_number) {
        $this->initMocks();

        $filename = "scenario".sprintf('%02d', $scenario_number).".yml";
        $scenario_runner = $this->scenarioRunner();
        $scenario_data = $scenario_runner->loadScenario($filename);
        $scenario_runner->runScenario($scenario_data);
        $scenario_runner->validateScenario($scenario_data);
    }

    protected function scenarioRunner() {
        if (!isset($this->scenario_runner)) {
            $this->scenario_runner = $this->app->make('\ScenarioRunner');
        }
        return $this->scenario_runner;
    }

    protected function initMocks() {
        if (!isset($this->mocks_inited)) {
            $this->mocks_inited = true;

            $mock_builder = new \InsightAPIMockBuilder();
            $mock_builder->installMockInsightClient($this->app, $this);

        }
        return $this->mocks_inited;
    }

    protected function resetForScenario() {
        // reset the DB
        $this->teardownDb();
        $this->setUpDb();

        // drain the queue
        $q = $this->app->make('Illuminate\Queue\QueueManager');
        while ($q->connection('notifications_out')->pop()) {}

    }

}
