<?php

return [

    'connection_string' => getenv('XCPD_CONNECTION_STRING') ?: 'http://localhost:4000',
    'rpc_user'          => getenv('XCPD_RPC_USER')          ?: null,
    'rpc_password'      => getenv('XCPD_RPC_PASSWORD')      ?: null,

];

