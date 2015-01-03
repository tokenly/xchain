<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class SendRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddSend()
    {
        // insert
        $send_model = $this->sendHelper()->createSampleSend();

        // load from repo
        $send_repo = $this->app->make('App\Repositories\SendRepository');
        $loaded_send_model = $send_repo->findByTXID($send_model['txid']);
        PHPUnit::assertNotEmpty($loaded_send_model);
        PHPUnit::assertEquals($send_model['txid'], $loaded_send_model['txid']);
        PHPUnit::assertEquals($send_model['uuid'], $loaded_send_model['uuid']);

        $loaded_send_model = $send_repo->findByUuid($send_model['uuid']);
        PHPUnit::assertNotEmpty($loaded_send_model);
        PHPUnit::assertEquals($send_model['txid'], $loaded_send_model['txid']);
        PHPUnit::assertEquals($send_model['uuid'], $loaded_send_model['uuid']);
    }


    public function testDeleteSend()
    {
        // insert
        $created_send_model = $this->sendHelper()->createSampleSend();
        $send_repo = $this->app->make('App\Repositories\SendRepository');

        // delete
        PHPUnit::assertTrue($send_repo->delete($created_send_model));

        // load from repo
        $loaded_send_model = $send_repo->findByTXID($created_send_model['txid']);
        PHPUnit::assertEmpty($loaded_send_model);
    }


    public function testUpdateSend()
    {
        // insert
        $created_send_model = $this->sendHelper()->createSampleSend();
        $send_repo = $this->app->make('App\Repositories\SendRepository');

        // update
        $send_repo->update($created_send_model, ['block_confirmed_hash' => 'hash001', ]);

        // load from repo
        $loaded_send_model = $send_repo->findByUuid($created_send_model['uuid']);
        PHPUnit::assertNotEmpty($loaded_send_model);
        PHPUnit::assertEquals('hash001', $loaded_send_model['block_confirmed_hash']);
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

    protected function sendHelper() {
        if (!isset($this->send_helper)) { $this->send_helper = $this->app->make('SampleSendsHelper'); }
        return $this->send_helper;
    }

}
