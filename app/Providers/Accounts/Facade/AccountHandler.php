<?php

namespace App\Providers\Accounts\Facade;

use Illuminate\Support\Facades\Facade;

class AccountHandler extends Facade {


    protected static function getFacadeAccessor() { return 'accounthandler'; }


}
