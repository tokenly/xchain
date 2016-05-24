<?php

namespace App\Http\Controllers\API\Base;

use App\Http\Controllers\Controller;
use Exception;

class APIController extends Controller {

    public function __construct() {
        $this->addMiddleware();
    }

    public function addMiddleware() {
        // log all API calls
        $this->middleware('api.logApiCalls');

        // catch all errors and return a JSON response
        $this->middleware('api.catchErrors');

        // require hmacauth middleware for all API requests by default
        $this->middleware('api.protectedAuth');
    }


}
