<?php

return [

	'default' => 'sqlite',
	// 'default' => 'mysql',

	'connections' => [

		'sqlite' => [
			'driver'   => 'sqlite',
			'database' => ':memory:',
			'prefix'   => '',
		],

        'mysql' => [
            'driver'    => 'mysql',
            'host'      => 'localhost',
            'database'  => 'tokenly_xchain_test',
            'username'  => 'root',
            'password'  => '',
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
        ],

	],

];
