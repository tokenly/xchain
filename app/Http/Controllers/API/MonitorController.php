<?php 

namespace App\Http\Controllers\API;

use App\Commands\CreateAccount;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Monitor\CreateMonitorRequest;
use App\Http\Requests\API\Monitor\UpdateMonitorRequest;
use App\Repositories\MonitoredAddressRepository;
use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class MonitorController extends APIController {



    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(APIControllerHelper $helper, MonitoredAddressRepository $address_respository)
    {
        // all monitors
        return $helper->index($address_respository);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(APIControllerHelper $helper, CreateMonitorRequest $request, MonitoredAddressRepository $address_respository, Guard $auth)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $attributes = $request->only(array_keys($request->rules()));
        $attributes['user_id'] = $user['id'];

        $address = $address_respository->create($attributes);

        EventLog::log('monitor.created', json_decode(json_encode($address)));
        return $helper->transformResourceForOutput($address);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIControllerHelper $helper, MonitoredAddressRepository $address_respository, $id)
    {
        return $helper->show($address_respository, $id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(APIControllerHelper $helper, UpdateMonitorRequest $request, MonitoredAddressRepository $address_respository, $id)
    {
        return $helper->update($address_respository, $id, $helper->getAttributesFromRequest($request));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(APIControllerHelper $helper, MonitoredAddressRepository $address_respository, $id)
    {
        return $helper->destroy($address_respository, $id);
    }

}
