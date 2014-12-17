<?php namespace App\Console;

use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel {

	/**
	 * The Artisan commands provided by your application.
	 *
	 * @var array
	 */
	protected $commands = [
		'App\Console\Commands\InspireCommand',

		// xchain commands
		'App\Console\Commands\Development\PopulateNotificationCommand',
		'App\Console\Commands\Blocks\LoadMissingBlocksCommand',
		'App\Console\Commands\APIUser\APIUserCommand',
		'App\Console\Commands\APIUser\APIListUsersCommand',
	];

	/**
	 * Define the application's command schedule.
	 *
	 * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
	 * @return void
	 */
	protected function schedule(Schedule $schedule)
	{
		$schedule->command('inspire')
				 ->hourly();
	}

}
