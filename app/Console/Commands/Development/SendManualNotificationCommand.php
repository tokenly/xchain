<?php

namespace App\Console\Commands\Development;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Exception;

class SendManualNotificationCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:send-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a manuaul notification (for development)';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('block', 'b', InputOption::VALUE_OPTIONAL, 'Block JSON file or height')
            ->addOption('transaction', 't', InputOption::VALUE_NONE, 'Process transaction')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Source Address')
            ->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'Destination Address')
            ->addOption('asset', 'a', InputOption::VALUE_OPTIONAL, 'Asset (LTBCOIN, BTC)', 'BTC')
            ->addOption('quantity', 'u', InputOption::VALUE_OPTIONAL, 'Quantity (float)', 1.0)
            ->addOption('confirmations', 'c', InputOption::VALUE_OPTIONAL, 'Confirmations', 1)
            ->addOption('confirmed-block-height', 'i', InputOption::VALUE_OPTIONAL, 'Confirmed Block Height')
            ->addOption('txid', 'x', InputOption::VALUE_OPTIONAL, 'Transaction ID', '1001')
            ->setHelp(<<<EOF
Manually send a notification
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

        $block_json_filename = $this->input->getOption('block');
        if ($block_json_filename) {
            $this->handleBlockNotification($block_json_filename);
        } else if ($this->input->getOption('transaction')) {
            $this->sendTransaction();
        } else {
            $this->error("Please specify a block (-b) or transaction (-t) notification type.");
        }


        // $filename = "scenario".sprintf('%02d', $scenario_number).".yml";
        // $scenario_runner = $this->laravel->make('\ScenarioRunner');

        // $this->comment("running $filename");
        // $scenario_data = $scenario_runner->loadScenario($filename);
        // if ($endpoint) {
        //     foreach ($scenario_data['monitoredAddresses'] as $offset => $monitored_address) {
        //         $scenario_data['monitoredAddresses'][$offset]['webhook_endpoint'] = $endpoint;
        //     }
        // }
        // $scenario_runner->runScenario($scenario_data);
        $this->comment("done");


    }

    protected function handleBlockNotification($block_json_filename_or_height) {
        if (file_exists($block_json_filename_or_height)) {
            $loaded_block_data = json_decode(file_get_contents($block_json_filename_or_height), true);
            if (!is_array($loaded_block_data)) { throw new Exception("Unable to decode file $block_json_filename_or_height", 1); }
        } else {
            $loaded_block_data = [
                'height' => $block_json_filename_or_height,
            ];
        }

        // build the block data
        $block_helper = $this->laravel->make('\SampleBlockHelper');
        $default_block_data = $block_helper->loadSampleBlock('default_parsed_block_01.json');
        $loaded_block_data = $block_helper->fillFakeBlockData($loaded_block_data);
        if (isset($loaded_block_data['tx'])) { unset($default_block_data['tx']); }
        $block_data = array_replace_recursive($default_block_data, $loaded_block_data);

        $block_handler = $this->laravel->make('App\Handlers\XChain\XChainBlockHandler');
        $block_confirmations = $this->input->getOption('confirmations');
        $this->comment('sending block notification');
        $block_handler->generateAndSendNotifications($block_data, $block_confirmations);
    }

    protected function sendTransaction() {
        $this->comment('sending transaction notification');

        // load base event
        $scenario_runner = $this->laravel->make('ScenarioRunner');
        $base_scenario_data = $scenario_runner->loadScenarioByNumber(2);
        $event = $base_scenario_data['events'][0];

        // modify event
        $confirmations = $this->input->getOption('confirmations');
        $event['confirmations'] = $confirmations;
        $event['txid'] = str_pad($this->input->getOption('txid'), 64, '0', STR_PAD_LEFT);
        if (strlen($sender = $this->input->getOption('source'))) { $event['sender'] = $sender; }
        if (strlen($recipient = $this->input->getOption('destination'))) { $event['recipient'] = $recipient; }
        $asset = $this->input->getOption('asset');
        $event['asset'] = $asset;
        $event['isCounterpartyTx'] = ($asset === 'BTC' ? false : true);
        if ($event['isCounterpartyTx']) {
            // counterparty data
        } else {
            // remove xcp data
            $event['isCounterpartyTx'] = false;
        }
        $event['quantity'] = $this->input->getOption('quantity');

        // parse event
        $parsed_tx = $scenario_runner->transactionEventToParsedTransaction($event);

        // update parsed_tx with the blockhash if set
        if ($confirmed_block_height = $this->input->getOption('confirmed-block-height')) {
            $parsed_tx['bitcoinTx']['blockhash'] = str_pad($confirmed_block_height, 64, '0', STR_PAD_LEFT);
        }

        // send notifications
        $tx_handler = $this->laravel->make('App\Handlers\XChain\XChainTransactionHandler');
        $block_seq = 1; # <-- hard-coded for now
        $block_confirmation_time = null;
        $tx_handler->sendNotifications($parsed_tx, $confirmations, $block_seq, $block_confirmation_time);
    }

}
