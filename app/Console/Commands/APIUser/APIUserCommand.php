<?php

namespace App\Console\Commands\APIUser;

use App\Providers\EventLog\Facade\EventLog;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class APIUserCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:new-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new API User';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Email Address')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Password', null)
            ->setHelp(<<<EOF
Create a new user with API Credentials
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
        $user_repository = $this->laravel->make('App\Repositories\UserRepository');
        $user_vars = [
            'email' => $this->input->getOption('email'),
            'password' => $this->input->getOption('password'),

        ];
        $user_model = $user_repository->create($user_vars);
        
        // log
        EventLog::log('user.create.cli', $user_model, ['id', 'email', 'apikey']);
    }

}
