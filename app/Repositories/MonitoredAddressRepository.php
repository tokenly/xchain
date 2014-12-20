<?php

namespace App\Repositories;

use App\Models\MonitoredAddress;
use App\Models\User;
use App\Repositories\Contracts\APIResourceRepositoryContract;
use Illuminate\Database\Eloquent\Model;
use Rhumsaa\Uuid\Uuid;
use \Exception;

/*
* MonitoredAddressRepository
*/
class MonitoredAddressRepository implements APIResourceRepositoryContract
{

    public function createWithUser(User $user, $attributes) {
        if (!isset($user['id']) OR !$user['id']) { throw new Exception("User ID is required", 1); }

        $attributes['user_id'] = $user['id'];
        return $this->create($attributes);
    }

    public function create($attributes) {
        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }
        if (!isset($attributes['active'])) { $attributes['active'] = true; }

        return MonitoredAddress::create($attributes);
    }

    public function findAll() {
        return MonitoredAddress::all();
    }

    public function findByUuid($uuid) {
        return MonitoredAddress::where('uuid', $uuid)->first();
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

    public function findByAddress($address) {
        return MonitoredAddress::where('address', $address);
    }

    public function findByAddresses($addresses) {
        return MonitoredAddress::whereIn('address', $addresses);
    }

}
