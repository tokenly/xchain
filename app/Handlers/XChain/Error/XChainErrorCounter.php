<?php

namespace App\Handlers\XChain\Error;

use Exception;

/**
 * Counts errors for long running processes
 */
class XChainErrorCounter {

    var $error_count = 0;

    function __construct($max_address_parse_error_count) {
        $this->max_address_parse_error_count = $max_address_parse_error_count;
    }

    public function incrementErrorCount($amount=1) {
        $this->error_count += $amount;
    }

    public function getErrorCount() {
        return $this->error_count;
    }

    public function maxErrorCountReached() {
        return ($this->error_count >= $this->max_address_parse_error_count);
    }

}
