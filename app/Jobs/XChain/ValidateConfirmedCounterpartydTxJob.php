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

            case 'issuance':
                list($was_found, $is_valid, $modified_tx) = $this->validateIssuance($parsed_tx, $data);
                break;

            case 'broadcast':
                list($was_found, $is_valid, $modified_tx) = $this->validateBroadcast($parsed_tx, $data);
                break;

            default:
                EventLog::warning('counterparty.jobUnknownType', [
                    'msg'    => 'Unhandled counterparty action',
                    'action' => $counterparty_action,
                    'txid'   => $txid,
                ]);

                // zero out the counterparty action data, but keep the bitcoin transaction
                //  the counterparty data will be picked up with a 
                //  ApplyDebitsAndCreditsCounterpartyJob after the transaction is confirmed
                $modified_tx = $this->modifyCounterpartyAssetTransactionQuantity($modified_tx, 0);

                $was_found = true;
                $is_valid = true;
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
                    $modified_tx = $this->modifyCounterpartyAssetTransactionQuantity($modified_tx, 0);
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
                        $new_xcpd_quantity_sat = ($xcpd_quantity_sat < $parsed_quantity_sat ? $xcpd_quantity_sat : 0);
                        $modified_tx = $this->modifyCounterpartyAssetTransactionQuantity($modified_tx, $new_xcpd_quantity_sat);
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

    protected function validateIssuance($parsed_tx, $data) {
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
        $txid                    = $parsed_tx['txid'];
        $modified_tx             = $parsed_tx;
        $trial_counterparty_data = $parsed_tx['counterpartyTx'];

        $was_found = false;
        $is_valid  = false;

        try {
            $issuances = $this->xcpd_client->get_issuances(['filters' => ['field' => 'tx_hash', 'op' => '==', 'value' => $txid]]);

        } catch (Exception $e) {
            EventLog::logError('error.counterparty', $e);

            // received no result from counterparty
            $issuances = null;
            $is_valid  = null;
        }

        if ($issuances) {
            $issuance = $issuances[0];
            if ($issuance) {
                $is_valid  = true;
                $was_found = true;
                try {
                    if ($issuance['issuer'] != $trial_counterparty_data['sources'][0]) {
                        throw new Exception("mismatched source: {$issuance['source']} (xcpd) != {$trial_counterparty_data['sources'][0]} (parsed)", 1);
                    }

                    $xcpd_quantity_sat = $issuance['quantity'];

                    // if token is not divisible, adjust to satoshis
                    $is_divisible = !!$issuance['divisible'];
                    if (!$is_divisible) { $xcpd_quantity_sat = CurrencyUtil::valueToSatoshis($xcpd_quantity_sat); }

                    // compare issuance quantity
                    if ($xcpd_quantity_sat != $trial_counterparty_data['quantitySat']) {
                        EventLog::warning('counterparty.issuanceMismatchedQuantity', [
                            'txid'       => $txid,
                            'xcpd_qty'   => $xcpd_quantity_sat,
                            'parsed_qty' => $trial_counterparty_data['quantitySat'],
                            'asset'      => $issuance['asset'],
                            'issuer'     => $issuance['issuer'],
                        ]);
                        throw new Exception("mismatched quantity: {$xcpd_quantity_sat} (xcpd) != {$trial_counterparty_data['quantitySat']} (parsed)", 1);

                        // reset to zero, but keep bitcoin transaction
                        $modified_tx = $this->modifyCounterpartyAssetTransactionQuantity($modified_tx, 0);
                    }

                    // check asset
                    if ($issuance['asset'] != $trial_counterparty_data['asset']) {
                        throw new Exception("mismatched asset: {$issuance['asset']} (xcpd) != {$trial_counterparty_data['asset']} (parsed)", 1);
                    }

                    // check description
                    if ($issuance['description'] != $trial_counterparty_data['description']) {
                        throw new Exception("mismatched description: {$issuance['description']} (xcpd) != {$trial_counterparty_data['description']} (parsed)", 1);
                    }

                    EventLog::debug('counterparty.issuanceConfirmed', [
                        'issuer' => $issuance['issuer'],
                        'qty'    => $xcpd_quantity_sat,
                        'asset'  => $issuance['asset'],
                    ]);


                    // we'll let the credits and debits process pick this up
                    //   but still send the notification
                    $modified_tx = $this->modifyAssetAndValueQuantities($modified_tx, 0);

                } catch (Exception $e) {
                    EventLog::logError('error.counterpartyConfirm', $e, ['txid' => $txid]);
                    $is_valid = false;
                }
            }
        }    

        return [$was_found, $is_valid, $modified_tx];
    }


    protected function validateBroadcast($parsed_tx, $data) {
        // $data = [
        //     'tx'            => $parsed_tx,
        //     'confirmations' => $confirmations,
        //     'block_seq'     => $block_seq,
        //     'block_id'      => $block_id,
        // ];

        // [
        //     {
        //         "block_index": 428528,
        //         "timestamp": 1473158100,
        //         "locked": 0,
        //         "source": "1Pq7HXD5i9ZXXfnt71xPmtEkyATWc57bRw",
        //         "fee_fraction_int": 0,
        //         "value": -1,
        //         "text": "BLOCKSCAN VERIFY-ADDRESS 7a4exlyjw97esst",
        //         "tx_index": 561297,
        //         "tx_hash": "40c592beaf966697c2d052321dec817d41dd7257ec8a4ae667cb5f0af56a0496",
        //         "status": "valid"
        //     }
        // ]

        // {
        //     "type": "broadcast",
        //     "sources": [
        //         "1FwkKA9cqpNRFTpVaokdRjT9Xamvebrwcu"
        //     ],
        //     "source": "1FwkKA9cqpNRFTpVaokdRjT9Xamvebrwcu",
        //     "timestamp": 1471660320,
        //     "value": -1,
        //     "fee_fraction": 0,
        //     "message": "This is a test of the emergency broadcast system. ABC123"
        // }

        // validate from counterpartyd
        $txid                    = $parsed_tx['txid'];
        $modified_tx             = $parsed_tx;
        $trial_counterparty_data = $parsed_tx['counterpartyTx'];

        $was_found = false;
        $is_valid  = false;

        try {
            $broadcasts = $this->xcpd_client->get_broadcasts(['filters' => ['field' => 'tx_hash', 'op' => '==', 'value' => $txid]]);

        } catch (Exception $e) {
            EventLog::logError('error.counterparty', $e);

            // received no result from counterparty
            $broadcasts = null;
            $is_valid  = null;
        }

        if ($broadcasts) {
            $broadcast = $broadcasts[0];
            if ($broadcast) {
                $is_valid  = true;
                $was_found = true;
                try {
                    if ($broadcast['source'] != $trial_counterparty_data['sources'][0]) {
                        throw new Exception("mismatched source: {$broadcast['source']} (xcpd) != {$trial_counterparty_data['sources'][0]} (parsed)", 1);
                    }
                    if ($broadcast['source'] != $trial_counterparty_data['source']) {
                        throw new Exception("mismatched source: {$broadcast['source']} (xcpd) != {$trial_counterparty_data['sources'][0]} (parsed)", 1);
                    }

                    // check timestamp
                    if ($broadcast['timestamp'] != $trial_counterparty_data['timestamp']) {
                        throw new Exception("mismatched timestamp: {$broadcast['timestamp']} (xcpd) != {$trial_counterparty_data['timestamp']} (parsed)", 1);
                    }

                    // check value
                    if ($broadcast['value'] != $trial_counterparty_data['value']) {
                        throw new Exception("mismatched value: {$broadcast['value']} (xcpd) != {$trial_counterparty_data['value']} (parsed)", 1);
                    }

                    // check fee_fraction
                    if ($broadcast['fee_fraction_int'] != $trial_counterparty_data['fee_fraction']) {
                        throw new Exception("mismatched fee_fraction: {$broadcast['fee_fraction_int']} (xcpd) != {$trial_counterparty_data['fee_fraction']} (parsed)", 1);
                    }

                    // check message
                    if ($broadcast['text'] != $trial_counterparty_data['message']) {
                        throw new Exception("mismatched message: {$broadcast['text']} (xcpd) != {$trial_counterparty_data['message']} (parsed)", 1);
                    }

                    EventLog::debug('counterparty.broadcastConfirmed', [
                        'source' => $broadcast['source'],
                        'message'  => $broadcast['text'],
                    ]);


                    // we'll let the credits and debits process pick this up
                    //   but still send the notification
                    $modified_tx = $this->modifyAssetAndValueQuantities($modified_tx, 0);

                } catch (Exception $e) {
                    EventLog::logError('error.counterpartyConfirm', $e, ['txid' => $txid]);
                    $is_valid = false;
                }
            }
        }    

        return [$was_found, $is_valid, $modified_tx];
    }


    // ------------------------------------------------------------------------

    protected function modifyAssetAndValueQuantities($tx, $new_xcpd_quantity_sat=0) {
        $old_counterparty_tx = $tx['counterpartyTx'];
        $old_values          = $tx['values'];

        $modified_tx = $this->modifyCounterpartyAssetTransactionQuantity($tx, $new_xcpd_quantity_sat);
        $modified_tx['counterpartyTx'] = $old_counterparty_tx;
        $modified_tx['values']         = $old_values;

        return $modified_tx;
    }

    protected function modifyCounterpartyAssetTransactionQuantity($tx, $new_xcpd_quantity_sat=0) {
        $new_xcpd_quantity_float = CurrencyUtil::satoshisToValue($new_xcpd_quantity_sat);
        $modified_tx = $tx;

        // keep the bitcoin the same, but reset the asset quantity to 0
        $modified_tx['counterpartyTx']['quantitySat'] = $new_xcpd_quantity_sat;
        $modified_tx['counterpartyTx']['quantity'] = CurrencyUtil::satoshisToValue($new_xcpd_quantity_sat);

        // set values to the new value
        $destination_address = isset($tx['destinations'][0]) ? $tx['destinations'][0] : null;
        $modified_tx['values'] = collect($modified_tx['values'])->map(function($value, $k) use ($new_xcpd_quantity_float, $destination_address) {
            // change the primary destination address
            if ($k == $destination_address) { return $new_xcpd_quantity_float; }

            // don't change any others
            return $value;
        })->toArray();

        // set spentAssets to $new_xcpd_quantity_float
        $modified_tx['spentAssets'] = collect($modified_tx['spentAssets'])->map(function($values, $address) use ($new_xcpd_quantity_float) {
            return collect($values)->map(function($quantity, $asset) use ($new_xcpd_quantity_float) {
                return ($asset == 'BTC' ? $quantity : $new_xcpd_quantity_float);
            })->toArray();
        })->toArray();

        // set receivedAssets to 0
        $modified_tx['receivedAssets'] = collect($modified_tx['receivedAssets'])->map(function($values, $address) use ($new_xcpd_quantity_float) {
            return collect($values)->map(function($quantity, $asset) use ($new_xcpd_quantity_float) {
                return ($asset == 'BTC' ? $quantity : $new_xcpd_quantity_float);
            })->toArray();
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
