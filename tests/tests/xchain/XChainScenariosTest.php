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
            $this->runScenario($i);
        }
    }


    protected function runScenario($scenario_number) {
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

}
