<?php

namespace App\Repositories;

use App\Models\Block;
use App\Models\MonitoredAddress;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Rhumsaa\Uuid\Uuid;
use \Exception;

/*
* NotificationRepository
*/
class NotificationRepository
{

    public function createForMonitoredAddress(MonitoredAddress $address, $attributes) {
        if (isset($attributes['monitored_address_id'])) { throw new Exception("monitored_address_id not allowed", 1); }
        if (isset($attributes['user_id'])) { throw new Exception("user_id not allowed", 1); }
        $attributes['monitored_address_id'] = $address['id'];
        $attributes['user_id'] = $address['user_id'];

        return $this->create($attributes);
    }

    public function createForUser(User $user, $attributes) {
        if (isset($attributes['monitored_address_id'])) { throw new Exception("monitored_address_id not allowed", 1); }
        if (isset($attributes['user_id'])) { throw new Exception("user_id not allowed", 1); }
        $attributes['monitored_address_id'] = null;
        $attributes['user_id'] = $user['id'];

        return $this->create($attributes);
    }


    public function create($attributes) {
        if (!isset($attributes['txid'])) { throw new Exception("TXID is required", 1); }
        if (!isset($attributes['monitored_address_id']) AND !isset($attributes['user_id'])) { throw new Exception("monitored_address_id or user_id is required", 1); }
        if (!isset($attributes['confirmations'])) { throw new Exception("confirmations is required", 1); }

        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }
        if (!isset($attributes['status'])) { $attributes['status'] = 'new'; }

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

    public function findByBlockId($block_id) {
        return Notification::where('block_id', $block_id)->get();
    }


    public function updateByUuid($uuid, $attributes) {
        $model = $this->findByUuid($uuid);
        if (!$model) { throw new Exception("Unable to find model for uuid $uuid", 1); }
        return $this->update($model, $attributes);
    }

    public function update(Model $notification, $attributes) {
        return $notification->update($attributes);
    }


    public function deleteByUuid($uuid) {
        if ($notification = self::findByUuid($uuid)) {
            return self::delete($notification);
        }
        return false;
    }

    public function delete(Model $notification) {
        return $notification->delete();
    }


    public function archive($notification) {
        DB::transaction(function() use ($notification) {
            $create_vars = $notification->getOriginal();

            // set the block hash
            $block = app('App\Repositories\BlockRepository')->findByID($create_vars['block_id']);
            $create_vars['block_hash'] = $block['hash'];

            // clear the unused variables
            unset($create_vars['block_id']);
            unset($create_vars['updated_at']);

            // create the new one
            DB::table('notification_archive')->insert($create_vars);

            // delete the old one
            self::delete($notification);
        });
    }

}
