<?php

namespace App\Providers\DateProvider;

use Carbon\Carbon;
use Exception;

/**
* DateProvider facade
*/
class DateProvider {

    protected $now = null;

    public function __construct() {
    }

    public function now() {
        if ($this->now !== null) { return $this->now->copy(); }

        return Carbon::now();
    }

    // for testing
    public function setNow(Carbon $now) {
        $this->now = $now;
    }

    public function microtimeNow() {
        if ($this->now !== null) { return $this->now->getTimestamp(); }

        return microtime(true);
    }

}


