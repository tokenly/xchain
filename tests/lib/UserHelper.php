<?php

use App\Repositories\UserRepository;

/**
*  UserHelper
*/
class UserHelper
{

    function __construct(UserRepository $user_repository) {
        $this->user_repository = $user_repository;
    }

    public function getSampleUser($email='sample@tokenly.co') {
        return $this->user_repository->findByEmail($email);
    }

    public function createSampleUser($override_vars=[]) {
        return $this->user_repository->create(array_merge($this->sampleVars(), $override_vars));
    }

    public function sampleVars($override_vars=[]) {
        return array_merge([
            'apitoken'         => 'TESTAPITOKEN',
            'apisecretkey'     => 'TESTAPISECRET',
            'email'            => 'sample@tokenly.co',
            'password'         => 'foo',
            'webhook_endpoint' => 'http://localhost/foo',
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return $this->sampleVars($override_vars);
    }

}