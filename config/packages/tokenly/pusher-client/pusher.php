<?php

return [

    'serverUrl' => getenv('PUSHER_SERVER_URL') ?: 'http://localhost:8000',
    'password'  => getenv('PUSHER_PASSWORD')   ?: null,

];

