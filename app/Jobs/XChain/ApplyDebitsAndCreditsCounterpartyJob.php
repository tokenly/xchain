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
* ApplyDebitsAndCreditsCounterpartyJob
*/
class ApplyDebitsAndCreditsCounterpartyJob
{
    public function __construct(Client $xcpd_client, Dispatcher $events, Cache $asset_cache, BlockRepository $block_repository)
    {
        $this->xcpd_client      = $xcpd_client;
        $this->events           = $events;
        $this->asset_cache      = $asset_cache;
        $this->block_repository = $block_repository;
    }

    public function fire($job, $data) {
        $block_height = $data['block_height'];

        $is_valid = $this->findDebitsAndCredits($block_height, $data);


        if ($is_valid === null) {
            // no response from conterpartyd
            if ($job->attempts() > 240) {
                // permanent failure
                EventLog::logError('counterparty.checkCreditsDebits.noResponse.permanent', ['block_height' => $block_height, ]);
                $job->delete();

            } else {
                // no response - bury this task and try again
                $release_time = $this->releaseTimeFromAttempts($job->attempts);

                // put it back in the queue
                $msg = "counterparty check credits and debits failed.  No response from counterpartyd.";

                EventLog::warning('counterparty.checkCreditsDebits.noResponse', [
                    'msg'          => $msg,
                    'block_height' => $block_height,
                ]);
                $job->release($release_time);
            }

            

        } else if ($is_valid === true) {
            EventLog::debug('counterparty.checkCreditsDebits.complete', [
                'blockHeight' => $block_height,
            ]);

            // if all went well, delete the job
            $job->delete();

        } else if ($is_valid === false) {
            // this job wasn't completed
            //   but it might be found if we try a few more times
            $attempts = $job->attempts();
            if ($attempts >= 4) {
                // we've already tried 4 times - give up
                // Log::debug("Send $txid was not found by counterpartyd after attempt ".$attempts.". Giving up.");
                $msg = "Failure after attempt ".$attempts.". Giving up.";
                EventLog::warning('counterparty.checkCreditsDebits.failure', [
                    'msg'      => $msg,
                    'attempts' => $attempts,
                ]);

                $job->delete();
            } else {
                $release_time = ($attempts > 2 ? 10 : 2);
                if ($attempts > 4) { $release_time = 20; }
                // Log::debug("Send $txid was not found by counterpartyd after attempt ".$attempts.". Trying again in {$release_time} seconds.");
                $msg = "Check credits and debits failed by counterpartyd after attempt ".$attempts.". Trying again in {$release_time} seconds.";
                EventLog::debug('counterparty.checkCreditsDebits.retry', [
                    'msg'      => $msg,
                    'attempts' => $attempts,
                    'release'  => $release_time,
                ]);

                $job->release($release_time);
            }
        }
    }

    protected function findDebitsAndCredits($block_height, $data) {
        $is_valid = true;

        $recent_block_height = $block_height - 6;

        try {
            // get all debits (open order, issuance fee)
            $debits = $this->xcpd_client->get_debits([
                'filters' => [
                    ['field' => 'block_index', 'op' => '>=', 'value' => $recent_block_height],
                    ['field' => 'block_index', 'op' => '<=', 'value' => $block_height],
                    ['field' => 'action', 'op' => 'IN', 'value' => [
                        'open order', 'issuance fee', 'dividend', 'dividend fee',
                    ]],
                ]
            ]);

            // get all credits (order matched, cancelled or expired)
            $credits = $this->xcpd_client->get_credits([
                'filters' => [
                    ['field' => 'block_index', 'op' => '>=', 'value' => $recent_block_height],
                    ['field' => 'block_index', 'op' => '<=', 'value' => $block_height],
                    ['field' => 'calling_function', 'op' => 'IN', 'value' => [
                        'order match','filled','cancel order','order cancelled','order expired','dividend','issuance',
                    ]],
                ]
            ]);

        } catch (Exception $e) {
            EventLog::logError('error.counterparty', $e);

            // received no result from counterparty
            $sends    = null;
            $is_valid = null;

            return null;
        }

        // process all debits and credits
        $items = collect($debits)->map(function($item) {
            return [
                'type'        => 'debit',
                'action'      => $item['action'],
                'asset'       => $item['asset'],
                'quantity'    => $item['quantity'],
                'address'     => $item['address'],
                'event'       => $item['event'],
                'block_index' => $item['block_index'],
                'fingerprint' => $this->buildFingerprint('debit', ['debit',$item['action'],$item['asset'],$item['quantity'],$item['address'],$item['event'],]),
            ];
        })->merge(collect($credits)->map(function($item) {
            return [
                'type'        => 'credit',
                'action'      => $item['calling_function'],
                'asset'       => $item['asset'],
                'quantity'    => $item['quantity'],
                'address'     => $item['address'],
                'event'       => $item['event'],
                'block_index' => $item['block_index'],
                'fingerprint' => $this->buildFingerprint('credit', ['credit',$item['calling_function'],$item['asset'],$item['quantity'],$item['address'],$item['event'],]),
            ];
        }));


        // get the current block height to 

        foreach($items as $item) {
            try {
                // generate the event
                $balance_change_event = $this->generateBalanceChangeEvent($item, $block_height, $data);

                // fire the event
                $this->fireBalanceChangeEvent($balance_change_event, $block_height, $data);

            } catch (Exception $e) {
                EventLog::logError('counterparty.checkCreditsDebits.error', $e, [
                    'asset'   => $item['asset'],
                    'action'  => $item['action'],
                    'address' => $item['address'],
                ]);
            }
        }


        return $is_valid;
    }

    protected function generateBalanceChangeEvent($item, $block_height, $data) {
        $is_divisible = $this->asset_cache->isDivisible($item['asset']);

        if ($is_divisible) {
            $quantity_sat = $item['quantity'];
            $quantity = CurrencyUtil::satoshisToValue($quantity_sat);
        } else {
            $quantity = $item['quantity'];;
            $quantity_sat = CurrencyUtil::valueToSatoshis($quantity);
        }

        $balance_change_event = [
            'event'            => $item['type'],
            'network'          => 'counterparty',

            'blockheight'      => $item['block_index'],
            'timestamp'        => time(),

            'asset'            => $item['asset'],
            'quantity'         => $quantity,
            'quantitySat'      => $quantity_sat,
            'address'          => $item['address'],
            'counterpartyData' => $item,
            'fingerprint'      => $item['fingerprint'],
        ];

        return $balance_change_event;
    }



    // ------------------------------------------------------------------------

    protected function fireBalanceChangeEvent($balance_change_event, $block_height, $data) {
        // handle the parsed tx now
        $block = $this->block_repository->findByID($data['block_id']);
        if (!$block) { throw new Exception("Block not found: {$data['block_id']}", 1); }

        $confirmations = $block_height - $balance_change_event['blockheight'];

        try {
            $this->events->fire('xchain.balanceChange.confirmed', [$balance_change_event, $confirmations, $block]);
        } catch (Exception $e) {
            EventLog::logError('error.confirmingTx', $e);
            usleep(500000); // sleep 0.5 seconds to prevent runaway errors
            throw $e;
        }
    }

    protected function releaseTimeFromAttempts($attempts) {
        $release_time = 2;

        if ($attempts > 60) {
            $release_time = 60;
        } else if ($attempts > 30) {
            $release_time = 30;
        } else if ($attempts > 20) {
            $release_time = 20;
        } else if ($attempts > 10) {
            $release_time = 5;
        } else {
            $release_time = 2;
        }

        return $release_time;
    }

    protected function buildFingerprint($type, $pieces) {
        switch ($type) {
            case 'credit': $prefix = 'CRDT'; break;
            case 'debit':  $prefix = 'DEBT'; break;
            default:
                throw new Exception("unknown type $type", 1);
        }
        return $prefix.substr(hash('sha256', implode('|', $pieces)), 4);
    }

}
