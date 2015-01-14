<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class UserRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddUser()
    {
        // insert
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $created_user_model = $user_repo->create($this->app->make('\UserHelper')->sampleDBVars());

        // load from repo
        $loaded_user_model = $user_repo->findByUuid($created_user_model['uuid']);
        PHPUnit::assertNotEmpty($loaded_user_model);
        PHPUnit::assertEquals($created_user_model['id'], $loaded_user_model['id']);
        PHPUnit::assertEquals('sample@tokenly.co', $loaded_user_model['email']);
        PHPUnit::assertNotEquals('foo', $loaded_user_model['password']);
    }


    public function testFindUserByID()
    {
        // insert
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $created_user_model = $user_repo->create($this->app->make('\UserHelper')->sampleDBVars());

        // load from repo
        $id = (string)$created_user_model['id'];
        $loaded_user_model = $user_repo->findByID($id);
        PHPUnit::assertNotEmpty($loaded_user_model);
        PHPUnit::assertEquals('TESTAPITOKEN', $loaded_user_model['apitoken']);
        PHPUnit::assertEquals($id, $loaded_user_model['id']);
    }


    public function testFindByAPIToken()
    {
        // insert
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $created_user_model = $user_repo->create($this->app->make('\UserHelper')->sampleDBVars());

        // load from repo
        $loaded_user_model = $user_repo->findByAPIToken('TESTAPITOKEN');
        PHPUnit::assertNotEmpty($loaded_user_model);
        PHPUnit::assertEquals('TESTAPITOKEN', $loaded_user_model['apitoken']);
    }


    public function testFindUserWithWebhookEndpoint()
    {
        // insert
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $created_user_model = $user_repo->create($this->app->make('\UserHelper')->sampleDBVars());

        // load from repo
        $users = $user_repo->findWithWebhookEndpoint();
        PHPUnit::assertNotEmpty($users);
        PHPUnit::assertCount(1, $users);
        foreach ($users as $user) { break; }
        PHPUnit::assertEquals('TESTAPITOKEN', $user['apitoken']);
    }



    public function testDeleteByUUID()
    {
        // insert
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $user_helper = $this->app->make('\UserHelper');
        $created_user = $user_repo->create($user_helper->sampleDBVars());

        // delete
        PHPUnit::assertTrue($user_repo->deleteByUuid($created_user['uuid']));

        // load from repo
        $loaded_user_model = $user_repo->findByUuid($created_user['uuid']);
        PHPUnit::assertEmpty($loaded_user_model);
    }

    public function testUpdateByUUID()
    {
        // insert
        $user_repo = $this->app->make('App\Repositories\UserRepository');
        $created_user_model = $user_repo->create($this->app->make('\UserHelper')->sampleDBVars());

        // update
        $user_repo->updateByUuid($created_user_model['uuid'], ['email' => 'sample2@tokenly.co', ]);

        // load from repo again
        $loaded_user_model = $user_repo->findByUuid($created_user_model['uuid']);
        PHPUnit::assertNotEmpty($loaded_user_model);
        PHPUnit::assertEquals('sample2@tokenly.co', $loaded_user_model['email']);
        PHPUnit::assertEquals($created_user_model['password'], $loaded_user_model['password']);
    }


}
