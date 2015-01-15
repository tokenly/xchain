<?php

namespace App\Pusher;

use Nc\FayeClient\Client as FayeClient;

class Client {

    public function __construct(FayeClient $faye, $password) {
        $this->faye     = $faye;
        $this->password = $password;
    }

    public function send($channel, $data = array(), $ext = array()) {
        if (!isset($ext['password'])) { $ext['password'] = $this->password; }
        return $this->faye->send($channel, $data, $ext);
    }


}