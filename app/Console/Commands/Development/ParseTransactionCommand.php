<?php

namespace App\Console\Commands\Development;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class ParseTransactionCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:parse-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse a transaction';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('protocol-version', 'p', InputOption::VALUE_OPTIONAL, 'Protocol version (1 or 2)', 2)
            ->addArgument('transaction-id', InputArgument::REQUIRED, 'Transaction ID')
            ->setHelp(<<<EOF
Parse a transaction
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

        $transaction_id = $this->input->getArgument('transaction-id');
        $protocol_version = $this->input->getOption('protocol-version');
        $scenario_runner = $this->laravel->make('\ScenarioRunner');
        $this->comment("loading from insight");
        $insight = $this->laravel->make('Tokenly\Insight\Client');
        $insight_tx_data = $insight->getTransaction($transaction_id);

        $xstalker_data = [
            'ver' => 1,
            'ts'  => time() * 1000,
            'tx'  => $insight_tx_data,
        ];

        $parsed_tx = $this->laravel->make('App\Listener\Builder\ParsedTransactionDataBuilder')->buildParsedTransactionData($xstalker_data);
        echo "\n\$parsed_tx:\n".json_encode($parsed_tx, 192)."\n\n";

        $this->comment("done");


    }

}
