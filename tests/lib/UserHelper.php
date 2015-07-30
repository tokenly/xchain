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

    public function getSampleUser($email='sample@tokenly.co', $token=null, $username=null) {
        $user = $this->user_repository->findByEmail($email);
        if (!$user) {
            if ($token === null) { $token = $this->testingTokenFromEmail($email); }
            if ($username === null) { $username = $this->usernameFromEmail($email); }
            // $user = $this->newSampleUser(['email' => $email, 'username' => $username, 'apitoken' => $token]);
            $user = $this->newSampleUser(['email' => $email, 'apitoken' => $token]);
        }
        return $user;
    }

    public function newRandomUser($override_vars=[]) {
        return $this->newSampleUser($override_vars, true);
    }

    public function createSampleUser($override_vars=[]) { return $this->newSampleUser($override_vars); }

    public function newSampleUser($override_vars=[], $randomize=false) {
        $create_vars = array_merge($this->sampleVars(), $override_vars);

        if ($randomize) {
            $create_vars['email'] = $this->randomEmail();
            // $create_vars['username'] = $this->usernameFromEmail($create_vars['email']);
            $create_vars['apitoken'] = $this->testingTokenFromEmail($create_vars['email']);
        }

        return $this->user_repository->create($create_vars);
    }

    public function sampleVars($override_vars=[]) {
        return array_merge([
            // 'name'         => 'Sample User',
            'email'        => 'sample@tokenly.co',
            // 'username'     => 'leroyjenkins',
            'password'     => 'foopass',
            'webhook_endpoint' => 'http://localhost/foo',

            'apitoken'     => 'TESTAPITOKEN',
            'apisecretkey' => 'TESTAPISECRET',
        ], $override_vars);
    }

    public function sampleDBVars($override_vars=[]) {
        return $this->sampleVars($override_vars);
    }

    public function testingTokenFromEmail($email) {
        switch ($email) {
            case 'sample@tokenly.co': return 'TESTAPITOKEN';
            default:
                // user2@tokenly.co => TESTUSER2TOKENLYCO
                return substr('TEST'.strtoupper(preg_replace('!^[^a-z0-9]$!i', '', $email)), 0, 16);
        }
        // code
    }

    public function usernameFromEmail($email) {
        return substr('t_'.strtoupper(preg_replace('!^[^a-z0-9]$!i', '', $email)), 0, 16);
    }

    public function randomEmail() {
        return 'u'.substr(md5(uniqid('', true)), 0, 6).'@tokenly.co';
    }


}