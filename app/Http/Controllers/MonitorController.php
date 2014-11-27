<?php namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CreateMonitorRequest;
use App\Repositories\MonitoredAddressRepository;
use Illuminate\Support\Facades\Log;

class MonitorController extends Controller {

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        // all monitors
        
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    // public function create()
    // {
    //  //
    // }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(CreateMonitorRequest $request, MonitoredAddressRepository $address_respository)
    {
        $attributes = $request->only(['address', 'monitorType', 'active',]);

        $new_address = $address_respository->create($attributes);

        return json_encode($new_address->serializeForAPI());
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    // public function edit($id)
    // {
    //  //
    // }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update($id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        //
    }

}
