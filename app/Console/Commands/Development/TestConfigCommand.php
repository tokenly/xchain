<?php

namespace App\Console\Commands\Development;

use Tokenly\LaravelEventLog\Facade\EventLog;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class TestConfigCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:test-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Config (for development)';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setHelp(<<<EOF
Test Config
EOF
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        // $c = \Carbon\Carbon::parse('-5 minutes');
        // print "time: $c\n";

        // $now = \Carbon\Carbon::now();
        // $old_time = \Carbon\Carbon::parse('-2 minutes');
        // $seconds_ago = $old_time->diffInSeconds($now);
        // print "seconds_ago: $seconds_ago\n";

        // $sc = app('Tokenly\ConsulHealthDaemon\ServicesChecker');
        // $sc->checkTotalQueueJobsVelocity([
        //     'btctx' => [10, '2 minutes'],
        // ]);


        $row = ['foo' => 'bar', 'baz' => 'bar2'];
        EventLog::log('test.event', $row);

        // \Illuminate\Support\Facades\Event::fire('consul-health.console.check');

        $this->comment("done");

    }


}
