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
    <script src="{{$pusherUrl}}/public/client.js"></script>
    <script src="/js/TransactionStreamer.js"></script>
    <script>window.Streamer.init('{{$pusherUrl}}');</script>
@stop