<?php namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\RecordLock\Facade\RecordLock;

class Inspire extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'inspire';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Display an inspiring quote';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		// $this->comment(PHP_EOL.Inspiring::quote().PHP_EOL);

		// set up database
		$default_driver = DB::getDefaultConnection();

		// set the untransacted database driver to be a mirror of the default driver
		$app = app();
		$connections = $app['config']['database.connections'];
		config(['database.connections.untransacted' => $connections[$default_driver]]);


		$connections = $app['config']['database.connections.untransacted'];
		echo "\$connections: ".json_encode($connections, 192)."\n";
		return;

		DB::transaction(function() {
			$number = rand(1, 9999);
			$this->comment('in transaction - writing non-committed '.$number);

			// do something in a transaction that will not be committed
			$res = DB::table('failed_jobs')->insert(['payload' => $number]);
			echo "\$res: ".json_encode($res, 192)."\n";

			// // do something else in a transaction that will not be committed
			$this->info('in transaction - writing committed '.($number+1).' to untransacted connection');
			$res = DB::connection('untransacted')->table('failed_jobs')->insert(['payload' => $number+1]);
			echo "\$res: ".json_encode($res, 192)."\n";

			// DB::connection('untransacted')->insert('insert into failed_jobs (connection, queue, payload, failed_at) values (?, ?, ?, ?)', ['foo','foo','foo', $number+1, Carbon::now()]);

			// bail
			throw new Exception("Bailing on outside transaction");
		});

	}

}
