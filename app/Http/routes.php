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

// create a notification to be notified about certain types of transactions regardless of address
$router->resource('api/v1/event_monitors', 'API\EventMonitorController', ['except' => ['create','edit']]);

// get all transactions for an address
$router->get('api/v1/transactions/{addressId}', 'API\TransactionController@index');

// create an unmanaged address
$router->post('api/v1/unmanaged/addresses', ['as' => 'createUnmanagedAddress', 'uses' => 'API\PaymentAddressController@createUnmanaged']);
// delete an unmanaged address
$router->delete('api/v1/unmanaged/addresses/{addressId}', ['as' => 'deleteUnmanagedAddress', 'uses' => 'API\PaymentAddressController@destroyUnmanaged']);

// create a multisig address
$router->post('api/v1/multisig/addresses', ['as' => 'createMultisigAddress', 'uses' => 'API\PaymentAddressController@createMultisig']);
// delete a multisig address
$router->delete('api/v1/multisig/addresses/{addressId}', ['as' => 'deleteMultisigAddress', 'uses' => 'API\PaymentAddressController@destroyMultisig']);

// publish and sign a multisig send
$router->post('api/v1/multisig/sends/{addressId}', ['as' => 'createMultisigSend', 'uses' => 'API\MultisigSendController@publishSignedSend']);
// delete a multisig send
$router->delete('api/v1/multisig/sends/{sendId}', ['as' => 'deleteMultisigSend', 'uses' => 'API\MultisigSendController@deleteSend']);
// check on the status of a multisig send
$router->get('api/v1/multisig/sends/{sendId}', ['as' => 'getMultisigSend', 'uses' => 'API\MultisigSendController@getSend']);

// publish and sign a multisig issuance
$router->post('api/v1/multisig/issuances/{addressId}', ['as' => 'createMultisigIssuance', 'uses' => 'API\MultisigIssuanceController@proposeSignAndPublishIssuance']);

// create a send
$router->post('api/v1/sends/{addressId}', 'API\SendController@create');
$router->post('api/v1/multisends/{addressId}', 'API\SendController@createMultisend');

// create an unsigned send
$router->post('api/v1/unsigned/sends/{addressId}', ['as' => 'composeSend', 'uses' => 'API\UnmanagedPaymentAddressSendController@composeSend']);
$router->delete('api/v1/unsigned/sends/{sendId}', ['as' => 'revokeSend', 'uses' => 'API\UnmanagedPaymentAddressSendController@revokeSend']);

// submit an externally signed send
$router->post('api/v1/signed/send/{sendId}', 'API\UnmanagedPaymentAddressSendController@submitSend');
$router->post('api/v1/signed/sends/{sendId}', ['as' => 'submitSend', 'uses' => 'API\UnmanagedPaymentAddressSendController@submitSend']);


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
$router->get('api/v1/accounts/balances/{addressId}', ['as' => 'accountBalance', 'uses' => 'API\AccountBalancesController@balances']);       # combined and by account


// transfer
$router->post('api/v1/accounts/transfer/{addressId}', 'API\AccountBalancesController@transfer');


// get asset info
$router->get('api/v1/assets/{asset}', ['as' => 'assetInfo', 'uses' => 'API\AssetController@get']);
$router->get('api/v1/assets', ['as' => 'multipleAssetInfo', 'uses' => 'API\AssetController@getMultiple']);

// health check
$router->get('/healthcheck/{checkType}', '\Tokenly\ConsulHealthDaemon\HealthController\HealthController@healthcheck');

//verify signed messages and validate addresses
$router->get('api/v1/message/verify/{address}', 'API\AddressController@verifyMessage');
$router->post('api/v1/message/sign/{address}', 'API\AddressController@signMessage');
$router->get('api/v1/validate/{address}', ['as' => 'validateAddress', 'uses' => 'API\AddressController@validateAddress']);

// estimate fee
$router->post('api/v1/estimatefee/{addressId}', 'API\SendController@estimateFee');

// cleanup UTXOs
$router->post('api/v1/cleanup/{addressId}', 'API\SendController@cleanup');

