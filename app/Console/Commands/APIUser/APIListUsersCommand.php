<?php

namespace App\Console\Commands\APIUser;

use App\Providers\EventLog\Facade\EventLog;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class APIListUsersCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:list-users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List API Users';


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('email', 'e', InputOption::VALUE_OPTIONAL, 'Filter by Email Address')
            ->setHelp(<<<EOF
Show User API Credentials
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
        $email = $this->input->getOption('email');
        if ($email) {
            $user = $user_repository->findByEmail($email);
            if ($user) {
                $users[] = $user;
            } else {
                $users = [];
            }
        } else {
            $users = $user_repository->findAll();
        }

        foreach($users as $user) {
            $user['password'] = '********';
            $this->line(json_encode($user, 192));
        }
    }

}
