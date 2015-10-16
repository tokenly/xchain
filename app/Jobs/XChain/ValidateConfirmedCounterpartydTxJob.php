<?php

namespace App\Jobs\XChain;

use App\Repositories\BlockRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XCPDClient\Client;
use \Exception;

/*
* ValidateConfirmedCounterpartydTxJob
*/
class ValidateConfirmedCounterpartydTxJob
{
    public function __construct(Client $xcpd_client, Dispatcher $events, Cache $asset_cache, BlockRepository $block_repository)
    {
        $this->xcpd_client      = $xcpd_client;
        $this->events           = $events;
        $this->asset_cache      = $asset_cache;
        $this->block_repository = $block_repository;
    }

    public function fire($job, $data) {
        Log::debug("ValidateConfirmedCounterpartydTxJob called.\ntxid=".json_encode($data['tx']['txid'], 192));

        // $data = [
        //     'tx'            => $parsed_tx,
        //     'confirmations' => $confirmations,
        //     'block_seq'     => $block_seq,
        //     'block_id'      => $block_id,
        // ];

        // {
        //     "destination": "1H42mKvwutzE4DAip57tkAc9KEKMGBD2bB",
        //     "source": "1MFHQCPGtcSfNPXAS6NryWja3TbUN9239Y",
        //     "quantity": 1050000000000,
        //     "block_index": 355675,
        //     "tx_hash": "5cbaf7995e7a8337861a30a65f5d751550127f63fccdb8a9b307efc26e6aa28b",
        //     "tx_index": 234609,
        //     "status": "valid",
        //     "asset": "LTBCOIN"
        // }

        // validate from counterpartyd
        // xcp_command -c get_sends -p '{"filters": {"field": "tx_hash", "op": "==", "value": "address"}}'
        $parsed_tx = $data['tx'];
        $tx_hash = $parsed_tx['txid'];

        try {
            $sends = $this->xcpd_client->get_sends(['filters' => ['field' => 'tx_hash', 'op' => '==', 'value' => $tx_hash]]);
        } catch (Exception $e) {
            EventLog::logError('error.counterparty', $e);

            // received no result from counterparty
            $sends = null;
        }

        $is_valid = null;
        if ($sends) {
            $send = $sends[0];
            if ($send) {
                $is_valid = true;
                try {
                    if ($send['destination'] != $parsed_tx['destinations'][0]) { throw new Exception("mismatched destination: {$send['destination']} (xcpd) != {$parsed_tx['destinations'][0]} (parsed)", 1); }

                    $xcpd_quantity_sat = $send['quantity'];

                    // if token is not divisible, adjust to satoshis
                    $is_divisible = $this->asset_cache->isDivisible($send['asset']);
                    if (!$is_divisible) { $xcpd_quantity_sat = CurrencyUtil::valueToSatoshis($xcpd_quantity_sat); }


                    // compare send quantity
                    $parsed_quantity_sat = CurrencyUtil::valueToSatoshis($parsed_tx['values'][$send['destination']]);
                    if ($xcpd_quantity_sat != $parsed_quantity_sat) { throw new Exception("mismatched quantity: {$xcpd_quantity_sat} (xcpd) != {$parsed_quantity_sat} (parsed)", 1); }


                    // check asset
                    if ($send['asset'] != $parsed_tx['asset']) { throw new Exception("mismatched asset: {$send['asset']} (xcpd) != {$parsed_tx['asset']} (parsed)", 1); }

                    Log::debug("Send $tx_hash was confirmed by counterpartyd.  {$xcpd_quantity_sat} {$send['asset']} to {$send['destination']}");

                } catch (Exception $e) {
                    EventLog::logError('error.counterpartyConfirm', $e, ['txid' => $tx_hash]);
                    $is_valid = false;
                }
            }
        }

        if ($is_valid === null) {
            // no response from conterpartyd
            if ($job->attempts() > 240) {
                // permanent failure
                EventLog::logError('job.failed.permanent', ['txid' => $tx_hash,]);
                $job->delete();

            } else {
                // no response - bury this task and try again
                $release_time = null;
                $attempts = $job->attempts();
                if ($job->attempts() > 60) {
                    $release_time = 60;
                } else if ($job->attempts() > 30) {
                    $release_time = 30;
                } else if ($job->attempts() > 20) {
                    $release_time = 20;
                } else if ($job->attempts() > 10) {
                    $release_time = 5;
                } else {
                    $release_time = 2;
                }

                // put it back in the queue
                Log::debug("Send $tx_hash was not confirmed by counterpartyd yet.  putting it back in the queue for $release_time seconds.");
                $job->release($release_time);
            }

            

        } else if ($is_valid === true) {
            // valid send - return it
            $data['tx']['counterpartyTx']['validated'] = true;

            // handle the parsed tx now
            $block = $this->block_repository->findByID($data['block_id']);
            if (!$block) { throw new Exception("Block not found: {$data['block_id']}", 1); }

            try {
                $this->events->fire('xchain.tx.confirmed', [$data['tx'], $data['confirmations'], $data['block_seq'], $block]);
            } catch (Exception $e) {
                EventLog::logError('error.confirmingTx', $e);
                usleep(500000); // sleep 0.5 seconds to prevent runaway errors
                throw $e;
            }

            // if all went well, delete the job
            $job->delete();

        } else if ($is_valid === false) {
            // this send was not valid
            //   delete it
            $job->delete();

        }
    }

}
