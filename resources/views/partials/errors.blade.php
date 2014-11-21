
@if ($errors->has())
<div data-alert class="alert-box alert">
    <a href="#" class="close">&times;</a>
    <div class="errors">
        @foreach ($errors->all() as $error)
        <div class="error">{{ $error }}</div>
        @endforeach
    </div>
</div>
@endif

@if(Session::has('error'))
<div data-alert class="alert-box alert">
    <a href="#" class="close">&times;</a>
    <div class="errors">
        <div class="error">{{ Session::get('error') }}</div>
    </div>
</div>
@endif

@if(Session::has('status'))
<div data-alert class="alert-box info">
    <a href="#" class="close">&times;</a>
    <div class="statuses">
        <div class="status">{{ Session::get('status') }}</div>
    </div>
</div>
@endif


