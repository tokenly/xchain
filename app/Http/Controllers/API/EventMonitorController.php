<?php 

namespace App\Http\Controllers\API;

use App\Commands\CreateAccount;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\EventMonitor\CreateEventMonitorRequest;
use App\Http\Requests\API\EventMonitor\UpdateEventMonitorRequest;
use App\Repositories\EventMonitorRepository;
use App\Repositories\NotificationRepository;
use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class EventMonitorController extends APIController {



    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(APIControllerHelper $helper, EventMonitorRepository $address_respository)
    {
        // all monitors
        return $helper->index($address_respository);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(APIControllerHelper $helper, CreateEventMonitorRequest $request, EventMonitorRepository $address_respository, Guard $auth)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $attributes = $request->only(array_keys($request->rules()));
        $attributes['user_id'] = $user['id'];

        $address = $address_respository->create($attributes);

        EventLog::log('eventMonitor.created', json_decode(json_encode($address)));
        return $helper->transformResourceForOutput($address);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIControllerHelper $helper, EventMonitorRepository $address_respository, $id)
    {
        return $helper->show($address_respository, $id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(APIControllerHelper $helper, UpdateEventMonitorRequest $request, EventMonitorRepository $address_respository, $id)
    {
        return $helper->update($address_respository, $id, $helper->getAttributesFromRequest($request));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(APIControllerHelper $helper, EventMonitorRepository $event_monitor_repository, NotificationRepository $notification_repository, Guard $auth, $monitored_address_uuid)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        return DB::transaction(function() use ($helper, $event_monitor_repository, $notification_repository, $monitored_address_uuid, $user) {
            // get the monitor
            $monitor = $helper->requireResourceOwnedByUser($monitored_address_uuid, $user, $event_monitor_repository);

            // archive all notifications first
            $notification_repository->findByEventMonitorId($monitor['id'])->each(function($notification) use ($notification_repository) {
                $notification_repository->archive($notification);
            });

            // delete the monitor
            EventLog::log('eventMonitor.delete', $monitor->serializeForAPI());
            return $helper->destroy($event_monitor_repository, $monitored_address_uuid, $user['id']);
        });

    }

}
