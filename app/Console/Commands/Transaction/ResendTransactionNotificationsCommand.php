<?php

namespace App\Console\Commands\Transaction;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ResendTransactionNotificationsCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:resend-transaction-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-sends old transaction notifications';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addArgument('transaction-id', InputArgument::REQUIRED, 'Transaction ID')
            ->setHelp(<<<EOF
Backfills any missing blocks
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
        $txid = $this->input->getArgument('transaction-id');

        $transaction_repository = app('App\Repositories\TransactionRepository');
        $transaction_model = $transaction_repository->findByTXID($txid);
        if (!$transaction_model) {
            $this->error("transaction not found for $txid");
            return;
        }

        $confirmations_builder = app('App\Blockchain\Block\ConfirmationsBuilder');
        $blockchain_store = app('App\Blockchain\Block\BlockChainStore');
        $latest_height = $blockchain_store->findLatestBlockHeight();
        $confirmations = $confirmations_builder->getConfirmationsForBlockHashAsOfHeight($transaction_model['block_confirmed_hash'], $latest_height);
        // Log::debug("\$transaction_model['block_confirmed_hash']={$transaction_model['block_confirmed_hash']} \$latest_height=$latest_height \$confirmations=$confirmations");

        // the block height might have changed if the chain was reorganized
        $parsed_tx = $transaction_model['parsed_tx'];
        $block = $blockchain_store->findByHash($transaction_model['block_confirmed_hash']);

        $confirmation_timestamp = null;
        if ($block) {
            $parsed_tx['bitcoinTx']['blockheight'] = $block['height'];
            $confirmation_timestamp = $block['parsed_block']['time'];
        }


        // fire the event
        $events = app('Illuminate\Contracts\Events\Dispatcher');
        Log::debug('manually sending event: '.json_encode(['tx'=>$parsed_tx, 'confirmations'=>$confirmations, 'seq'=>$transaction_model['block_seq'], 'ts'=>$confirmation_timestamp], 192));
        $events->fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, $transaction_model['block_seq'], $confirmation_timestamp]);
    }

}
