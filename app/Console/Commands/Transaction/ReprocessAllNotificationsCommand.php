<?php

namespace App\Console\Commands\Transaction;

use App\Repositories\NotificationRepository;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReprocessAllNotificationsCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:reprocess-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocesses notifications that were sent previously, but re-parses all transactions before resending.';


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['block-height', InputArgument::REQUIRED, 'block height to start with'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['dry-run', null, InputOption::VALUE_NONE, 'Dry run only'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $block_height = $this->input->getArgument('block-height');
        $this->is_dry_run = $this->option('dry-run');
        if ($this->is_dry_run) {
            $this->comment('DRY RUN ONLY');
        }

        $this->comment('DEBUG: OVERRIDDING DRY RUN');
        $this->is_dry_run = true;

        $block_repository = app('App\Repositories\BlockRepository');
        $notification_repository = app('App\Repositories\NotificationRepository');
        $all_blocks = $block_repository->findAllAsOfHeight($block_height);

        $new_parsed_txs_by_txid = [];
        foreach($all_blocks as $block) {
            $block_id = $block['id'];
            $sent_notifications = $notification_repository->findByBlockId($block_id);
            foreach($sent_notifications as $sent_notification) {
                $new_parsed_tx = $this->lookForChangedTransaction($sent_notification);
                if ($new_parsed_tx) {
                    // overwrite - take the newest one
                    Log::debug("adding transaction {$new_parsed_tx['txid']}");
                    $new_parsed_txs_by_txid[$new_parsed_tx['txid']] = $new_parsed_tx;
                }
            }
        }



        $this->info("Processing ".count($new_parsed_txs_by_txid)." transactions");

        foreach($new_parsed_txs_by_txid as $txid => $new_parsed_tx) {
            $this->resendParsedTx($new_parsed_tx);
        }
    }


    protected function lookForChangedTransaction($notification_model) {
        $notification_details = $notification_model['notification'];
        $event = $notification_details['event'];

        // skip blocks
        if ($event == 'block') {
            Log::debug("Ignoring block event {$notification_details['height']}");
            return;
        }

        // reparse the transaction (no cache)
        $txid = $notification_details['txid'];
        $transaction_store = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore');
        $transaction_model = $transaction_store->getParsedTransactionFromBitcoind($txid);
        if (!$transaction_model) {
            $this->error("transaction not found for $txid");
            return;
        }
        $parsed_tx = $transaction_model['parsed_tx'];

        // compare the existing notification event with the newly parsed transaction
        $is_the_same = true;
        // asset, network, values, sources, destinations
        $comparators = ['asset', 'network', 'sources', 'destinations',]; // 'values', 

        foreach($comparators as $comparator) {
            if (
                    json_encode($parsed_tx[$comparator])
                    != 
                    json_encode($notification_details[$comparator])
                ) {
                $is_the_same = false;
                break;
            }
        }

        if ($is_the_same) {
            // this notification was ok - skip it
            if ($this->is_dry_run) {
                $this->info("[DRY RUN] notification {$notification_model['uuid']} ({$notification_model['id']}) was ok");
            }

            return null;
        }

        // notification is different, return the new one
        return $parsed_tx;
    }

    protected function resendParsedTx($parsed_tx) {
        // get confirmations
        $blockchain_store      = app('App\Blockchain\Block\BlockChainStore');
        $confirmations_builder = app('App\Blockchain\Block\ConfirmationsBuilder');
        $latest_height = $blockchain_store->findLatestBlockHeight();
        $confirmations = $confirmations_builder->getConfirmationsForBlockHashAsOfHeight($parsed_tx['bitcoinTx']['blockhash'], $latest_height);

        // // fire the event
        if ($this->is_dry_run) {
            // $this->info('[DRY RUN] send event: '.json_encode(['confirmations'=>$confirmations, 'tx'=>$parsed_tx,], 192));
            $this->info('[DRY RUN] send event: '.json_encode(['confirmations'=>$confirmations, 'txid'=>$parsed_tx['txid'],], 192));
        } else {
            Log::debug('sending event: '.json_encode(['txid'=>$parsed_tx['txid'], 'confirmations'=>$confirmations,], 192));
            // Event::fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, $transaction_model['block_seq'], $block]);
        }

    }

}
