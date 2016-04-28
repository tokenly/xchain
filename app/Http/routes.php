<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

$router->get('/', 'HomeController@index');

// create a managed address (where xchain owns the private key)
$router->resource('api/v1/addresses', 'API\PaymentAddressController', ['except' => ['create','edit']]);

// create a monitor to be notified about all send and receive transactions to a certain address
$router->resource('api/v1/monitors', 'API\MonitorController', ['except' => ['create','edit']]);

// get all transactions for an address
$router->get('api/v1/transactions/{addressId}', 'API\TransactionController@index');

// create an unmanaged address
$router->post('api/v1/unmanaged/addresses', 'API\PaymentAddressController@createUnmanaged');

// create a send
$router->post('api/v1/sends/{addressId}', 'API\SendController@create');
$router->post('api/v1/multisends/{addressId}', 'API\SendController@createMultisend');

// create an unsigned send
$router->post('api/v1/unsigned/sends/{addressId}', 'API\UnmanagedPaymentAddressSendController@composeSend');
$router->delete('api/v1/unsigned/sends/{sendId}', 'API\UnmanagedPaymentAddressSendController@revokeSend');

// submit an externally signed send
$router->post('api/v1/signed/send/{sendId}', 'API\UnmanagedPaymentAddressSendController@submitSend');


// prime address
$router->get('api/v1/primes/{addressId}', 'API\PrimeController@getPrimedUTXOs');
$router->post('api/v1/primes/{addressId}', 'API\PrimeController@primeAddress');

// address balance
$router->get('api/v1/balances/{address}', 'API\BalancesController@show');


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

//verify signed messages and validate addresses
$router->get('api/v1/message/verify/{address}', 'API\AddressController@verifyMessage');
$router->post('api/v1/message/sign/{address}', 'API\AddressController@signMessage');
$router->get('api/v1/validate/{address}', 'API\AddressController@validateAddress');

// estimate fee
$router->post('api/v1/estimatefee/{addressId}', 'API\SendController@estimateFee');

// cleanup UTXOs
$router->post('api/v1/cleanup/{addressId}', 'API\SendController@cleanup');

