<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

$router->get('/', 'HomeController@index');

$router->resource('api/v1/monitors', 'API\MonitorController', ['except' => ['create','edit']]);
$router->resource('api/v1/addresses', 'API\PaymentAddressController', ['except' => ['create','edit']]);
$router->get('api/v1/transactions/{addressId}', 'API\TransactionController@index');

// create a send
$router->post('api/v1/sends/{addressId}', 'API\SendController@create');

// address balance
$router->get('api/v1/balances/{addressId}', 'API\BalancesController@show');

//get asset info
$router->get('api/v1/assets/{asset}', 'API\AssetController@get');

// health check
$router->get('/healthcheck/{checkType}', '\Tokenly\ConsulHealthDaemon\HealthController\HealthController@healthcheck');


/*
|--------------------------------------------------------------------------
| Authentication & Password Reset Controllers
|--------------------------------------------------------------------------
|
| These two controllers handle the authentication of the users of your
| application, as well as the functions necessary for resetting the
| passwords for your users. You may modify or remove these files.
|
*/

// $router->controller('auth', 'AuthController');

// $router->controller('password', 'PasswordController');

