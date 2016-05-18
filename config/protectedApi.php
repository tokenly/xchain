<?php

$API_PREFIX = '/api/v1';

return [

	// messages signed with a different host and path
    //   can still be valid for message signing purposes
	'allowedSubstitutions' => [

        'accountBalance' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/balances/{addressId}',
        ],

        'assetInfo' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/assets/{asset}',
        ],

        'validateAddress' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/validate/{address}',
        ],

        'createUnmanagedAddress' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/addresses',
        ],

        'deleteUnmanagedAddress' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/addresses/{addressId}',
        ],

        'composeSend' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/sends/{addressId}',
        ],

        'revokeSend' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/sends/{sendId}',
        ],

        'submitSend' => [
            'host'  => env('ALLOWED_API_SUBSTITION_HOST'),
            'route' => $API_PREFIX.'/signed/sends/{sendId}',
        ],

    ],

];
