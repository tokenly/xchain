<?php

namespace App\Blockchain\Sender;

use Exception;
use Illuminate\Support\Facades\Log;

class FeePriority {

    const MIN_PRIORITY = 1;
    const MAX_PRIORITY = 1000;

    const FEE_SATOSHIS_PER_BYTE_LOW  = 5;
    const FEE_SATOSHIS_PER_BYTE_MED  = 11;
    const FEE_SATOSHIS_PER_BYTE_HIGH = 41;

    public function isValid($fee_priority) {
        return ($this->getSatoshisPerByte($fee_priority) !== null);
    }

    public function getSatoshisPerByte($fee_priority) {
        if (is_numeric($fee_priority)) {
            if ($fee_priority >= self::MIN_PRIORITY AND $fee_priority <= self::MAX_PRIORITY) {
                return intval($fee_priority);
            }

            return null;
        }

        switch (strtolower(trim($fee_priority))) {
            case 'low':
            case 'lo':
                return self::FEE_SATOSHIS_PER_BYTE_LOW;

            case 'medlow':
            case 'lowmed':
                return ceil((self::FEE_SATOSHIS_PER_BYTE_LOW + self::FEE_SATOSHIS_PER_BYTE_MED) / 2);

            case 'medium':
            case 'med':
                return self::FEE_SATOSHIS_PER_BYTE_MED;

            case 'high':
            case 'hi':
                return self::FEE_SATOSHIS_PER_BYTE_HIGH;
        }

        return null;
    }

}
