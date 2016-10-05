<?php

namespace App\Repositories;

use App\Models\PaymentAddress;
use App\Models\User;
use App\Repositories\PaymentAddressArchiveRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\LaravelApiProvider\Contracts\APIResourceRepositoryContract;
use Tokenly\LaravelApiProvider\Filter\RequestFilter;
use Tokenly\TokenGenerator\TokenGenerator;
use \Exception;

/*
* PaymentAddressRepository
*/
class PaymentAddressRepository implements APIResourceRepositoryContract
{

    public function __construct(BitcoinAddressGenerator $address_generator, PaymentAddressArchiveRepository $payment_address_archive_repository) {
        $this->address_generator = $address_generator;
        $this->payment_address_archive_repository = $payment_address_archive_repository;
    }

    public function createWithUser(User $user, $attributes) {
        if (!isset($user['id']) OR !$user['id']) { throw new Exception("User ID is required", 1); }
        $attributes['user_id'] = $user['id'];

        return $this->create($attributes);
    }

    public function create($attributes) {
        // require user_id
        if (!isset($attributes['user_id']) OR !$attributes['user_id']) { throw new Exception("User ID is required", 1); }

        // create a uuid
        if (!isset($attributes['uuid'])) { $attributes['uuid'] = Uuid::uuid4()->toString(); }

        // determine type
        if (!isset($attributes['address_type'])) {
            $attributes['address_type'] = PaymentAddress::TYPE_P2PKH;
        }
        $address_type = $attributes['address_type'];

        // determine if we should create a private key token and/or a new address
        $should_create_address_token = false;
        $should_create_address       = false;
        if ($address_type == PaymentAddress::TYPE_P2PKH) {
            // P2PKH address
            if (!isset($attributes['private_key_token']) AND !isset($attributes['address'])) {
                // new managed address
                $should_create_address_token = true;
                $should_create_address       = true;
            } else if (!isset($attributes['private_key_token']) AND isset($attributes['address'])) {
                // an address without a private key token is an unmanaged address
                $should_create_address_token = false;
                $should_create_address       = false;
                $attributes['private_key_token'] = '';
            } else if (isset($attributes['private_key_token']) AND !isset($attributes['address'])) {
                // importing an existing private key token
                $should_create_address_token = false;
                $should_create_address       = true;
            }

        } else if ($address_type == PaymentAddress::TYPE_P2SH) {
            // multisig
            $should_create_address_token = !!isset($attributes['private_key_token']);
            $should_create_address       = false;

            $attributes['copay_status'] = PaymentAddress::COPAY_STATUS_PENDING;
        }

        // create a token
        if ($should_create_address_token) {
            $token_generator = new TokenGenerator();
            $token = $token_generator->generateToken(40, 'A');
            $attributes['private_key_token'] = $token;
        }

        // create an address
        if ($should_create_address AND $attributes['private_key_token']) {
            $new_address = $this->address_generator->publicAddress($attributes['private_key_token']);
            $attributes['address'] = $new_address;
        } else {
            if (!isset($attributes['address'])) { $attributes['address'] = ''; }
        }

        return PaymentAddress::create($attributes);
    }

    public function findAll(RequestFilter $filter=null) {
        return PaymentAddress::all();
    }

    public function findAllAddresses() {
        $addresses = [];
        foreach (PaymentAddress::all(['address']) as $model) {
            $addresses[] = $model['address'];
        }
        return $addresses;
    }

    public function findByID($id) {
        return PaymentAddress::find($id);
    }

    public function findByUuid($uuid) {
        return PaymentAddress::where('uuid', $uuid)->first();
    }

    public function updateByUuid($uuid, $attributes) {
        $model = $this->findByUuid($uuid);
        if (!$model) { throw new Exception("Unable to find model for uuid $uuid", 1); }
        return $this->update($model, $attributes);
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
        // old payment addresses never die...
        //   they just get moved to the archive

        return DB::transaction(function() use ($address) {
            $this->payment_address_archive_repository->create($address->getAttributes());

            // now delete it
            return $address->delete();
        });
    }

    public function hardDelete(Model $address) {
        return $address->delete();
    }

    public function findByAddress($address) {
        return PaymentAddress::where('address', $address);
    }

    public function findByAddresses($addresses) {
        return PaymentAddress::whereIn('address', $addresses);
    }

}
