@extends('layouts.base')

@section('body')

<div class="row">
	<div class="columns">
		<h1>
			@section('pageTitle')
			Welcome
			@show
		</h1>
		<hr>
	</div>
</div>


<div class="row">
	<div class="columns">
		@section('bodyContent')
		@show
	</div>
</div>

@stop



