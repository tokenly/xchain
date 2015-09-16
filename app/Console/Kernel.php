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
		// xchain commands
		'App\Console\Commands\Blocks\LoadMissingBlocksCommand',
		'App\Console\Commands\Transaction\ResendTransactionNotificationsCommand',

		'App\Console\Commands\Development\PopulateNotificationCommand',
		'App\Console\Commands\Development\SendManualNotificationCommand',
		'App\Console\Commands\Development\ExportWIFCommand',
		'App\Console\Commands\Development\ParseTransactionCommand',
		'App\Console\Commands\Development\PruneTransactionsCommand',
		'App\Console\Commands\Development\PruneBlocksCommand',
		'App\Console\Commands\Development\UpgradeAccountsCommand',
		'App\Console\Commands\Accounts\SweepAccountCommand',
		'App\Console\Commands\Accounts\ShowAccountsCommand',
		'App\Console\Commands\Accounts\ReconcileAccountsCommand',
		'App\Console\Commands\Accounts\ForceSyncAddressAccountsCommand',
		'App\Console\Commands\Accounts\CloseAccountCommand',

		// vendor commands
		'Tokenly\ConsulHealthDaemon\Console\ConsulHealthMonitorCommand',

		// API Provider commands
		\Tokenly\LaravelApiProvider\Commands\MakeAPIModelCommand::class,
		\Tokenly\LaravelApiProvider\Commands\MakeAPIRespositoryCommand::class,
	];

	/**
	 * Define the application's command schedule.
	 *
	 * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
	 * @return void
	 */
	protected function schedule(Schedule $schedule)
	{
		$schedule->command('xchain:prune-transactions')->daily();

	}

}
