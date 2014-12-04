<?php

namespace App\Console\Commands\Development;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class PopulateNotificationCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:populate-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a notification';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('endpoint', 'e', InputOption::VALUE_OPTIONAL, 'Webhook endpoint')
            ->addArgument('scenario', InputArgument::OPTIONAL, 'Scenario number to load', 1)
            ->setHelp(<<<EOF
Runs a scenario to load a notification into the notification queue
EOF
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {

        $scenario_number = $this->input->getArgument('scenario');
        $endpoint = $this->input->getOption('endpoint');
        $scenario_runner = $this->laravel->make('\ScenarioRunner');
        $filename = "scenario".sprintf('%02d', $scenario_number).".yml";
        $this->comment("running $filename");
        $scenario_data = $scenario_runner->loadScenario($filename);
        if ($endpoint) {
            foreach ($scenario_data['monitoredAddresses'] as $offset => $monitored_address) {
                $scenario_data['monitoredAddresses'][$offset]['webhook_endpoint'] = $endpoint;
            }
        }
        $scenario_runner->runScenario($scenario_data);
        $this->comment("done");


    }

}
