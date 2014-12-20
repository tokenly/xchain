<?php

namespace App\Http\Controllers\API\Base;

use App\Http\Controllers\Controller;
use Exception;

class APIController extends Controller {

    public function __construct() {
        // require hmacauth middleware for all API requests by default
        $this->middleware('hmacauth');
    }

}
