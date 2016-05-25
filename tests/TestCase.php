<?php

use Illuminate\Support\Facades\DB;

class TestCase extends Illuminate\Foundation\Testing\TestCase {

    protected $baseUrl = 'http://localhost';

    protected $useDatabase           = false;
    protected $useRealSQLiteDatabase = false;

	/**
	 * Creates the application.
	 *
	 * @return \Illuminate\Foundation\Application
	 */
	public function createApplication()
	{
		$app = require __DIR__.'/../bootstrap/app.php';

		$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

		return $app;
	}

    public function setUp()
    {
        // make sure we are using the testing environment
        parent::setUp();

        // bind pusher client mock
        app('Tokenly\PusherClient\Mock\MockBuilder')->installPusherMockClient($this);


        if($this->useDatabase)
        {
            $this->setUpDb();
        }

        if($this->useRealSQLiteDatabase)
        {
            $this->setUpRealSQLiteDb();
        }

        // reset sample TXID
        SampleTXOHelper::resetSampleTXID();
    }

    public function tearDown() {
        if($this->useRealSQLiteDatabase)
        {
            $this->tearDownRealSQLiteDb();
        }

        return parent::tearDown();
    }

    public function setUpDb()
    {
        // migrate the database
        $this->app['Illuminate\Contracts\Console\Kernel']->call('migrate');
    }

    public function setUpRealSQLiteDb()
    {
        if (stristr(env('DATABASE_DRIVER'), 'real') !== false) {
            \Illuminate\Support\Facades\Log::debug("NOT overriding database driver: ".env('DATABASE_DRIVER'));
            return;
        }

        $app = app();

        // set the untransacted database driver to be a mirror of the default driver
        $default_driver = 'testing_real_sqlite';
        DB::setDefaultConnection($default_driver);

        // duplicate the untransacted database driver
        $connections = $app['config']['database.connections'];
        config(['database.connections.untransacted' => $connections[$default_driver]]);

        // create the blank db sqlite file (even if it already exists)
        $path = $connections[$default_driver]['database'];
        if (is_dir(dirname($path))) { file_put_contents($path, ''); }

        // migrate the database
        $this->app['Illuminate\Contracts\Console\Kernel']->call('migrate');

        // also migrate the untrasacted in-memory database
        $this->app['Illuminate\Contracts\Console\Kernel']->call('migrate', ['--database' => 'untransacted']);
    }

    public function tearDownRealSQLiteDb() {
        $app = app();
        $default_driver = 'testing_real_sqlite';
        $connections = $app['config']['database.connections'];
        $path = $connections[$default_driver]['database'];
        if (file_exists($path) && is_dir(dirname($path))) { unlink($path); }
    }

    public function teardownDb()
    {
        // $this->app['Illuminate\Contracts\Console\Kernel']->call('migrate:reset');
    }

}
