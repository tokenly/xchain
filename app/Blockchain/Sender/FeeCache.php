<?php

namespace App\Blockchain\Sender;

use App\Providers\DateProvider\Facade\DateProvider;
use Exception;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\RecordLock\Facade\RecordLock;

class FeeCache
{

    const LONG_MINUTES         = 120;
    const TTL_MINUTES          = 10;
    const VALID_TTL_MINUTES    = 3;
    const HTTP_TIMEOUT_SECONDS = 5;

    const INTERVAL_SECONDS = 60;

    function __construct() {
        
    }

    public function getFeesList() {
        for ($i=0; $i < 2; $i++) { 
            try {
                return $this->feesListByInterval($this->currentInterval());
            } catch (Exception $e) {
                EventLog::logError('fees.failed.temporary', $e, ['attempt' => $i]);
                usleep(500000);
            }
        }
        EventLog::logError('fees.failed.permanent', $e);

        $backup_fees = Cache::get($this->longTermCacheKey());
        if ($backup_fees) { return $backup_fees; }

        throw new Exception("Unable to load fees", 1);
        
    }

    public function currentInterval() {
        $minutes = intval(floor(DateProvider::now()->getTimestamp() / self::INTERVAL_SECONDS));
        return intval($minutes * self::INTERVAL_SECONDS);
    }

    public function feesListByInterval($interval) {
        $key = $this->feesCacheKey($interval);

        $locked = RecordLock::acquire($key);
        if (!$locked) {
            EventLog::logError('lock.failed', 'failed to lock '.$key);
        }

        try {
            $fees = Cache::remember($key, self::TTL_MINUTES, function() use ($key, $interval) {
                $new_fees = $this->buildFeesList();
                $new_fees['interval'] = $interval;
                $new_fees['ts']       = DateProvider::now()->getTimestamp();
                $new_fees['key']      = $key;

                Cache::put($this->longTermCacheKey(), $new_fees, self::LONG_MINUTES);

                return $new_fees;
            });

            RecordLock::release($key);
            return $fees;

        } catch (Exception $e) {
            RecordLock::release($key);
            throw $e;
        }
    }

    public function buildFeesList() {
        $raw_data = $this->loadFromAPI();
        return $this->processData($raw_data);
    }

    public function processData($raw_data) {
        return $raw_data;
    }

    public function loadFromAPI() {
        $url = env('BITCOINFEES_URL');
        $client = new HttpClient();
        $res = $client->get($url, [
            'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            // 'read_timeout'    => self::HTTP_TIMEOUT_SECONDS,
            'timeout'    => self::HTTP_TIMEOUT_SECONDS,
        ]);
        $data = json_decode($res->getBody(), true);
        return $data['fees'];
    }

    protected function feesCacheKey($interval) {
        return 'feesmap.'.$interval;
    }

    protected function longTermCacheKey() {
        return 'feesmap.last';
    }


}
