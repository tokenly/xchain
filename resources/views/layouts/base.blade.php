<!doctype html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Tokenly | @yield('htmlTitle', 'Welcome')</title>
	<link rel="stylesheet" href="/css/foundation/foundation.css">
	<link rel="stylesheet" href="/css/styles.css">

	<script src="/js/vendor/modernizr.js"></script>
</head>
<body>
	<div id="wrapper">

		@section('header')
		@include('partials.header')
		@show


		@yield('body', '')

	</div>


	@section('footer')
	@include('partials.footer')
	@show

	@section('javascriptIncludes')
	<script src="/js/vendor/jquery.js"></script>
	<script src="/js/foundation.min.js"></script>
	@show
	@yield('appJavascriptIncludes')

	<script>
	@section('foundation_init')
	$(document).foundation();
	@show
	</script>

</body>
</html>
