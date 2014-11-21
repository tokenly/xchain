@extends('layouts.one-column')

@section('htmlTitle', 'X-Chain')

@section('pageTitle', 'X-Chain Demonstration')


@section('bodyContent')
	<div class="welcome">

        <p>This page shows the server reading bitcoin transactions and decoding Counterparty transactions in real time.</p>

	</div>

    <div class="transactionList">
    </div>
@stop

@section('appJavascriptIncludes')
    <!-- <script src="//faye.tokenly.dev:8200/public/client.js"></script> -->
    <script src="//pusher.dev01.tokenly.co/public/client.js"></script>
    <script src="/js/TransactionStreamer.js"></script>
@stop