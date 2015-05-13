<?php

use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;

/**
*  TestMemorySyncQueue
*/
class TestMemorySyncQueue extends SyncQueue
{

    static $MEMORY = [];

    public function __construct($default = 'default') {
        $this->default = $default;
    }

    public function pushRaw($payload, $queue = null, array $options = array())
    {
        $queue = $queue ?: $this->default;
        if (!isset(self::$MEMORY[$queue])) { self::$MEMORY[$queue] = []; }
        array_push(self::$MEMORY[$queue], $payload);
    }

    // takes the first value from the from of the array (FIFO)
    public function pop($queue = null) {
        $queue = $queue ?: $this->default;
        return isset(self::$MEMORY[$queue]) ? array_shift(self::$MEMORY[$queue]) : null;
    }

    public function drain($queue = null) {
        $queue = $queue ?: $this->default;
        return self::$MEMORY[$queue] = [];
    }

}