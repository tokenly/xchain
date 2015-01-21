<?php

return [

    'clientUrl' => getenv('PUSHER_CLIENT_URL') ?: 'http://localhost:8000',
    'serverUrl' => getenv('PUSHER_SERVER_URL') ?: 'http://localhost:8000',
    'password'  => getenv('PUSHER_PASSWORD')   ?: null,

];

