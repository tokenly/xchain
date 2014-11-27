<?php

namespace App\Repositories;

use App\Models\MonitoredAddress;
use Rhumsaa\Uuid\Uuid;
use \Exception;

/*
* MonitoredAddressRepository
*/
class MonitoredAddressRepository
{

    public function create($attributes) {
        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }
        if (!isset($attributes['active'])) { $attributes['active'] = true; }

        return MonitoredAddress::create($attributes);
    }

    public function findByUuid($uuid) {
        return Notification::where(['uuid', $uuid])->first();
    }

    public function findByAddress($address) {
        return MonitoredAddress::where('address', $address);
    }

    public function findByAddresses($addresses) {
        return MonitoredAddress::whereIn('address', $addresses);
    }

}
