<?php

namespace App\Providers\TXO\Facade;

use Illuminate\Support\Facades\Facade;

class TXOHandler extends Facade {


    protected static function getFacadeAccessor() { return 'txohandler'; }


}
