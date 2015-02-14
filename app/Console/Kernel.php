<?php namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel {

	/**
	 * The Artisan commands provided by your application.
	 *
	 * @var array
	 */
	protected $commands = [
		'App\Console\Commands\Inspire',

		// xchain commands
		'App\Console\Commands\Development\PopulateNotificationCommand',
		'App\Console\Commands\Development\SendManualNotificationCommand',
		'App\Console\Commands\Development\ExportWIFCommand',
		'App\Console\Commands\Development\TestConfigCommand',
		'App\Console\Commands\Development\ParseTransactionCommand',
		'App\Console\Commands\Blocks\LoadMissingBlocksCommand',
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
