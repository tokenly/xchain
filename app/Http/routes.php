<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

$router->get('/', 'HomeController@index');

$router->resource('api/v1/monitors', 'API\MonitorController', ['except' => ['create','edit']]);
$router->resource('api/v1/addresses', 'API\PaymentAddressController', ['except' => ['create','edit']]);
$router->get('api/v1/transactions/{addressId}', 'API\TransactionController@index');

// create a send
$router->post('api/v1/sends/{addressId}', 'API\SendController@create');
$router->post('api/v1/multisends/{addressId}', 'API\SendController@createMultisend');

// prime address
$router->get('api/v1/primes/{addressId}', 'API\PrimeController@getPrimedUTXOs');
$router->post('api/v1/primes/{addressId}', 'API\PrimeController@primeAddress');

// address balance
$router->get('api/v1/balances/{addressId}', 'API\BalancesController@show');


// accounts
$router->post('api/v1/accounts', 'API\AccountController@create');
$router->match(['post','patch','put'], 'api/v1/accounts/{accountId}', 'API\AccountController@update');
$router->get('api/v1/accounts/{addressId}', 'API\AccountController@index');
$router->get('api/v1/account/{accountId}', 'API\AccountController@show');


// account balances
$router->get('api/v1/accounts/balances/{addressId}', 'API\AccountBalancesController@balances');       # combined and by account


// transfer
$router->post('api/v1/accounts/transfer/{addressId}', 'API\AccountBalancesController@transfer');


// get asset info
$router->get('api/v1/assets/{asset}', 'API\AssetController@get');



// health check
$router->get('/healthcheck/{checkType}', '\Tokenly\ConsulHealthDaemon\HealthController\HealthController@healthcheck');
