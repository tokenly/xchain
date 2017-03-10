<?php

namespace App\Blockchain\Sender;

use App\Blockchain\Sender\FeeCache;
use Exception;
use Illuminate\Support\Facades\Log;

class FeePriority {

    const MIN_PRIORITY = 1;
    const MAX_PRIORITY = 1000;

    function __construct(FeeCache $fee_cache) {
        $this->fee_cache = $fee_cache;
    }

    public function isValid($fee_priority_string) {
        try {
            return !!$this->getSatoshisPerByte($fee_priority_string);
        } catch (Exception $e) {
            return false;
        }
    }

    public function getSatoshisPerByte($fee_priority_string) {
        if (stristr($fee_priority_string, 'block')) {
            $fee_priority_string = strtolower($fee_priority_string);
            if (preg_match('!^([\d.]+)\s*blocks?$!', $fee_priority_string, $matches)) {
                $blocks_delay = max(0, floatval($matches[1]) - 1);
                return $this->resolveBitcoinFee($blocks_delay);
            }
        }

        // resolve a number a fee per byte
        if (is_numeric($fee_priority_string)) {
            $fee_priority_string = intval($fee_priority_string);
            if ($fee_priority_string >= self::MIN_PRIORITY AND $fee_priority_string <= self::MAX_PRIORITY) {
                return intval($fee_priority_string);
            }
            throw new Exception("Invalid numeric fee: $fee_priority_string", 1);
        }

        $blocks_delay = null;
        switch (strtolower(trim($fee_priority_string))) {
            case 'low':
            case 'lo':
                $blocks_delay = 143; // 24 hours
                break;

            case 'medlow':
            case 'lowmed':
                $blocks_delay = 15; // 3 hours
                break;

            case 'medium':
            case 'med':
                $blocks_delay = 5; // 1 hour
                break;

            case 'medhigh':
            case 'highmed':
                $blocks_delay = 2; // 30 min.
                break;

            case 'high':
            case 'hi':
                $blocks_delay = 0; // 10 min
                break;
        }

        if ($blocks_delay === null) {
            throw new Exception("Unknown fee string: $fee_priority_string", 1);
        }

        return $this->resolveBitcoinFee($blocks_delay);
    }

    public function resolveBitcoinFee($blocks_delay) {
        $list = $this->fee_cache->getFeesList();

        list($previous, $current) = $this->getPreviousAndCurrentEntriesForBlocksDelay($blocks_delay, $list);
        $fee = $current['minFee'];
        if ($current['maxDelay'] < $blocks_delay) {
            $min_delay = $current['maxDelay'];
            $max_delay = $previous['maxDelay'];
            $min_fee = $previous['minFee'];
            $max_fee = $current['minFee'];

            if ($max_delay > $min_delay AND $max_fee > $min_fee) {
                $pct = ($blocks_delay - $min_delay) / ($max_delay - $min_delay);
                $fee = $max_fee - intval(round(($max_fee - $min_fee) * $pct));
            }
        }

        return $fee;
    }

    protected function getPreviousAndCurrentEntriesForBlocksDelay($blocks_delay, $list) {
        $previous = null;
        $count = count($list);
        foreach($list as $index => $entry) {
            if ($entry['maxDelay'] <= $blocks_delay OR $index >= $count - 1) {
                return [$previous === null ? $entry : $previous, $entry];
            }
            $previous = $entry;
        }

        // not found - should never get here
        throw new Exception("$blocks_delay blocks delay not found", 1);
    }

}
