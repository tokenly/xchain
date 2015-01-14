<?php

namespace App\Http\Middleware\XChain;

use App\Providers\EventLog\Facade\EventLog;
use Closure;
use Exception;
use Illuminate\Contracts\Routing\Middleware;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class HandleAPIErrors implements Middleware {

    /**
     * Create a new filter instance.
     *
     * @param  Guard  $auth
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            return $next($request);
        } catch (HttpResponseException $e) {
            // HttpResponseException can pass through
            throw $e;
        } catch (Exception $e) {
            EventLog::logError('error.api.uncaught', $e);
            Log::warning(get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString());

            // catch any uncaught exceptions
            //   and return a 500 response
            $response = new JsonResponse([
                'message' => 'Unable to process this request',
                'errors' => ['Unexpected error'],
            ], 500);
            return $response;
        }
    }

}
