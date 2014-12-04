<?php

namespace App\Repositories;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Rhumsaa\Uuid\Uuid;
use \Exception;

/*
* NotificationRepository
*/
class NotificationRepository
{

    public function create($address, $status='new', $attributes=[]) {
        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }

        $attributes['monitored_address_id'] = $address['id'];

        $attributes['status'] = $status;

        return Notification::create($attributes);
    }


    public function findById($id) {
        return Notification::find($id)->first();
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
