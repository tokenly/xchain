<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\APIControllerHelper;
use App\Http\Requests\API\CreateMonitorRequest;
use App\Http\Requests\API\UpdateMonitorRequest;
use App\Repositories\MonitoredAddressRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MonitorController extends Controller {

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
    public function store(APIControllerHelper $helper, CreateMonitorRequest $request, MonitoredAddressRepository $address_respository)
    {
        return $helper->store($address_respository, $request->only(array_keys($request->rules())));
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
        return $helper->update($address_respository, $id, $request->only(array_keys($request->rules())));
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