<?php

use Illuminate\Queue\SyncQueue;

/**
*  TestMemorySyncQueue
*/
class TestMemorySyncQueue extends SyncQueue
{

    protected $memory = [];

    public function __construct($default = 'default') {
        $this->default = $default;
    }

    public function pushRaw($payload, $queue = null, array $options = array())
    {
        $queue = $queue ?: $this->default;
        if (!isset($this->memory[$queue])) { $this->memory[$queue] = []; }
        array_push($this->memory[$queue], $payload);
    }

    public function pop($queue = null) {
        $queue = $queue ?: $this->default;
        return array_pop($this->memory[$queue]);
    }


}