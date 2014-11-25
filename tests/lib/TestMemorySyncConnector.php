<?php


use Illuminate\Queue\Connectors\ConnectorInterface;

class TestMemorySyncConnector implements ConnectorInterface {

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new \TestMemorySyncQueue($config['queue']);
    }

}
