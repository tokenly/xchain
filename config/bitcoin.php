<?php

return [

    'connection_string' => getenv('NATIVE_CONNECTION_STRING') ?: 'http://localhost:8332',
    'rpc_user'          => getenv('NATIVE_RPC_USER')          ?: null,
    'rpc_password'      => getenv('NATIVE_RPC_PASSWORD')      ?: null,

];

