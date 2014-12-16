<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class UserCommandTest extends TestCase {

    protected $useDatabase = true;

    public function testCLIAddUser()
    {
        // insert
        $this->app['Illuminate\Contracts\Console\Kernel']->call('xchain:new-user', ['--email' => 'samplecli@tokenly.co']);

        // load from repo
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $loaded_user_model = $user_repo->findByEmail('samplecli@tokenly.co');
        PHPUnit::assertNotEmpty($loaded_user_model);
        PHPUnit::assertGreaterThan(0, $loaded_user_model['id']);
        PHPUnit::assertEquals('samplecli@tokenly.co', $loaded_user_model['email']);
    }




}
