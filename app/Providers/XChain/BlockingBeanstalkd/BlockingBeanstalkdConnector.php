<?php

namespace App\Providers\XChain\BlockingBeanstalkd;

use App\Providers\XChain\BlockingBeanstalkd\BlockingBeanstalkdQueue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\PheanstalkInterface;

class BlockingBeanstalkdConnector implements ConnectorInterface {

	/**
	 * Establish a queue connection.
	 *
	 * @param  array  $config
	 * @return \Illuminate\Contracts\Queue\Queue
	 */
	public function connect(array $config)
	{
		$pheanstalk = new Pheanstalk($config['host'], array_get($config, 'port', PheanstalkInterface::DEFAULT_PORT));

		return new BlockingBeanstalkdQueue(
			$pheanstalk, $config['queue'], array_get($config, 'ttr', Pheanstalk::DEFAULT_TTR)
		);
	}

}
