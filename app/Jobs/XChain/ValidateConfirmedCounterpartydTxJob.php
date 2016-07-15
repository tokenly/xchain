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
        $parsed_tx           = $data['tx'];
        $txid                = $parsed_tx['txid'];
        $modified_tx         = $parsed_tx;
        $counterparty_action = $parsed_tx['counterpartyTx']['type'];

        $was_found = false;
        $is_valid  = null;
        switch ($counterparty_action) {
            case 'send':
                list($was_found, $is_valid, $modified_tx) = $this->validateSend($parsed_tx, $data);
                break;

            default:
                EventLog::warning('counterparty.jobUnknownType', [
                    'msg'    => 'Unknown counterparty action',
                    'action' => $counterparty_action,
                ]);
                $was_found = false;
                $is_valid = false;
                break;
        }

        if ($is_valid === null) {
            // no response from conterpartyd
            if ($job->attempts() > 240) {
                // permanent failure
                EventLog::logError('counterparty.job.failed.permanent', ['txid' => $txid, 'action' => $counterparty_action, ]);
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
                $msg = "counterparty job not confirmed.  No response from counterpartyd.";

                EventLog::warning('counterparty.jobUnconfirmed', [
                    'msg'     => $msg,
                    'action'  => $counterparty_action,
                    'txid'    => $txid,
                    'release' => $release_time,
                ]);
                $job->release($release_time);
            }

            

        } else if ($is_valid === true) {
            $this->fireCompletedTransactionEvent($modified_tx, $data);

            // if all went well, delete the job
            $job->delete();

        } else if ($is_valid === false) {
            if ($was_found) {
                // this send was found, but it was not a valid send
                //   delete it
                $job->delete();
            } else {
                // this send wasn't found by counterpartyd at all
                //   but it might be found if we try a few more times
                $attempts = $job->attempts();
                if ($attempts >= 4) {
                    // we've already tried 4 times - give up
                    // Log::debug("Send $txid was not found by counterpartyd after attempt ".$attempts.". Giving up.");
                    $msg = "Job was not found by counterpartyd after attempt ".$attempts.". Giving up.";
                    EventLog::warning('counterparty.jobUnconfirmed.failure', [
                        'msg'      => $msg,
                        'action'   => $counterparty_action,
                        'txid'     => $txid,
                        'attempts' => $attempts,
                    ]);

                    // reset the counterparty asset quantities to zero, but keep bitcoin transaction (and fee)
                    $modified_tx = $this->zeroTransactionQuantity($modified_tx);
                    $this->fireCompletedTransactionEvent($modified_tx, $data);

                    $job->delete();
                } else {
                    $release_time = ($attempts > 2 ? 10 : 2);
                    if ($attempts > 4) { $release_time = 20; }
                    // Log::debug("Send $txid was not found by counterpartyd after attempt ".$attempts.". Trying again in {$release_time} seconds.");
                    $msg = "$counterparty_action was not found by counterpartyd after attempt ".$attempts.". Trying again in {$release_time} seconds.";
                    EventLog::debug('counterparty.jobUnconfirmed.retry', [
                        'msg'      => $msg,
                        'action'   => $counterparty_action,
                        'txid'     => $txid,
                        'attempts' => $attempts,
                        'release'  => $release_time,
                    ]);

                    $job->release($release_time);
                }
            }

        }
    }

    protected function validateSend($parsed_tx, $data) {

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
        $txid        = $parsed_tx['txid'];
        $modified_tx = $parsed_tx;

        $was_found = false;
        $is_valid  = false;

        try {
            $sends = $this->xcpd_client->get_sends(['filters' => ['field' => 'tx_hash', 'op' => '==', 'value' => $txid]]);

        } catch (Exception $e) {
            EventLog::logError('error.counterparty', $e);

            // received no result from counterparty
            $sends    = null;
            $is_valid = null;
        }

        if ($sends) {
            $send = $sends[0];
            if ($send) {
                $is_valid  = true;
                $was_found = true;
                try {
                    if ($send['destination'] != $parsed_tx['destinations'][0]) { throw new Exception("mismatched destination: {$send['destination']} (xcpd) != {$parsed_tx['destinations'][0]} (parsed)", 1); }

                    $xcpd_quantity_sat = $send['quantity'];

                    // if token is not divisible, adjust to satoshis
                    $is_divisible = $this->asset_cache->isDivisible($send['asset']);
                    if (!$is_divisible) { $xcpd_quantity_sat = CurrencyUtil::valueToSatoshis($xcpd_quantity_sat); }


                    // compare send quantity
                    $parsed_quantity_sat = CurrencyUtil::valueToSatoshis($parsed_tx['values'][$send['destination']]);
                    if ($xcpd_quantity_sat != $parsed_quantity_sat) {
                        EventLog::warning('counterparty.mismatchedQuantity', [
                            'txid'        => $txid,
                            'xchain_qty'  => $xcpd_quantity_sat,
                            'parsed_qty'  => $parsed_quantity_sat,
                            'asset'       => $send['asset'],
                            'destination' => $send['destination'],
                        ]);

                        // reset to zero, but keep bitcoin transaction
                        $modified_tx = $this->zeroTransactionQuantity($modified_tx);
                    }


                    // check asset
                    if ($send['asset'] != $parsed_tx['asset']) { throw new Exception("mismatched asset: {$send['asset']} (xcpd) != {$parsed_tx['asset']} (parsed)", 1); }

                    // Log::debug("Send $txid was confirmed by counterpartyd.  {$xcpd_quantity_sat} {$send['asset']} to {$send['destination']}");
                    EventLog::debug('counterparty.sendConfirmed', [
                        'qty'         => $xcpd_quantity_sat,
                        'asset'       => $send['asset'],
                        'destination' => $send['destination'],
                    ]);

                } catch (Exception $e) {
                    EventLog::logError('error.counterpartyConfirm', $e, ['txid' => $txid]);
                    $is_valid = false;
                }
            }
        }    

        return [$was_found, $is_valid, $modified_tx];
    }




    // ------------------------------------------------------------------------

    protected function zeroTransactionQuantity($tx) {
        $modified_tx = $tx;

        // keep the bitcoin the same, but reset the asset quantity to 0
        $modified_tx['counterpartyTx']['quantity'] = 0;
        // set all values to 0
        $modified_tx['values'] = collect($modified_tx['values'])->map(function($value, $k) {
            return 0;
        })->toArray();

        return $modified_tx;
    }

    protected function fireCompletedTransactionEvent($modified_tx, $data) {
        // valid send - return it
        $modified_tx['counterpartyTx']['validated'] = true;

        // handle the parsed tx now
        $block = $this->block_repository->findByID($data['block_id']);
        if (!$block) { throw new Exception("Block not found: {$data['block_id']}", 1); }

        try {
            $this->events->fire('xchain.tx.confirmed', [$modified_tx, $data['confirmations'], $data['block_seq'], $block]);
        } catch (Exception $e) {
            EventLog::logError('error.confirmingTx', $e);
            usleep(500000); // sleep 0.5 seconds to prevent runaway errors
            throw $e;
        }
    }

}
