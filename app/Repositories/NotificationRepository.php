<?php

namespace App\Repositories;

use App\Models\MonitoredAddress;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Rhumsaa\Uuid\Uuid;
use \Exception;

/*
* NotificationRepository
*/
class NotificationRepository
{

    public function create(MonitoredAddress $address, $attributes) {
        if (!isset($attributes['txid'])) { throw new Exception("TXID is required", 1); }
        if (isset($attributes['monitored_address_id'])) { throw new Exception("monitored_address_id not allowed", 1); }

        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }
        if (!isset($attributes['status'])) { $attributes['status'] = 'new'; }

        $attributes['monitored_address_id'] = $address['id'];

        return Notification::create($attributes);
    }


    public function findById($id) {
        return Notification::find($id)->first();
    }

    public function findByMonitoredAddressId($monitored_address_id) {
        return Notification::where('monitored_address_id', $monitored_address_id)->orderBy('id')->get();
    }

    public function findByUuid($uuid) {
        return Notification::where('uuid', $uuid)->first();
    }


    public function updateByUuid($uuid, $attributes) {
        return $this->update($this->findByUuid($uuid), $attributes);
    }

    public function update(Model $address, $attributes) {
        return $address->update($attributes);
    }


    public function deleteByUuid($uuid) {
        if ($address = self::findByUuid($uuid)) {
            return self::delete($address);
        }
        return false;
    }

    public function delete(Model $address) {
        return $address->delete();
    }



}
