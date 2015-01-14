<?php

return [

    'host'     => getenv('INFLUX_DB_HOST')     ?: 'localhost',
    'port'     => getenv('INFLUX_DB_PORT')     ?: '4444',
    'username' => getenv('INFLUX_DB_USERNAME') ?: 'admin',
    'password' => getenv('INFLUX_DB_PASSWORD') ?: 'xchain',

];
