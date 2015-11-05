<?php

namespace App\Repositories;

use App\Models\Account;
use App\Models\PaymentAddress;
use App\Models\TXO;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
* TXORepository
*/
class TXORepository
{

    protected $model_type = 'App\Models\TXO';

    protected $prototype_model;

    function __construct() {
        $this->prototype_model = app($this->model_type);
    }


    public function findByID($id) {
        return $this->prototype_model->find($id);
    }

    public function findByTXID($txid) {
        return $this->prototype_model
            ->where(['txid' => $txid])
            ->get();
    }

    public function findByPaymentAddress(PaymentAddress $payment_address) {
        return $this->prototype_model
            ->where(['payment_address_id' => $payment_address['id']])
            ->get();
    }

    public function findByAccount(Account $account, $unspent=null) {
        $query = $this->prototype_model
            ->where(['account_id' => $account['id']]);

        // unspent filter
        if ($unspent !== null) {
            $query->where('spent', $unspent ? '0' : '1');
        }

        return $query->get();
    }

    public function findByTXIDAndOffset($txid, $offset) {
        return $this->prototype_model
            ->where(['txid' => $txid, 'n' => $offset])
            ->first();
    }

    public function deleteSpentOlderThan(Carbon $date) {
        $affected_rows = $this->prototype_model
            ->where('updated_at', '<', $date)
            ->where('spent', '1')
            ->delete();
        return $affected_rows;
    }

    public function deleteAll() {
        $affected_rows = $this->prototype_model
            ->where('spent', '1')
            ->delete();
        return $affected_rows;
    }

    public function delete(TXO $model) {
        return $model->delete();
    }

    public function update(TXO $model, $new_attributes) {
        return $model->update($new_attributes);
    }


    public function create(PaymentAddress $payment_address, Account $account, $attributes) {
        $create_vars = array_merge($attributes, [
            'payment_address_id' => $payment_address['id'],
            'account_id'         => $account['id'],
        ]);

        if (!isset($create_vars['txid'])) { throw new Exception("txid is required", 1); }
        if (!isset($create_vars['n'])) { throw new Exception("n is required", 1); }
        if (!isset($create_vars['type'])) { $create_vars['type'] = TXO::CONFIRMED; }
        if (!isset($create_vars['spent'])) { $create_vars['spent'] = false; }
        if (!isset($create_vars['script'])) { $create_vars['script'] = ''; }

        return $this->prototype_model->create($create_vars);
    }

    // if updating, then only the 
    public function updateIfExists($attributes) {
        if (!isset($attributes['txid'])) { throw new Exception("txid is required", 1); }
        if (!isset($attributes['n'])) { throw new Exception("n is required", 1); }

        $existing_model = $this->findByTXIDAndOffset($attributes['txid'], $attributes['n']);
        if ($existing_model) {
            $this->update($existing_model, $attributes);
            return $existing_model;
        }

        return null;
    }

    public function transferAccounts(TXO $model, Account $from, Account $to, $allowed_types=null) {
        if ($allowed_types === null) { $allowed_types = [TXO::UNCONFIRMED, TXO::CONFIRMED]; }

        return $model
            ->where('id', '=', $model['id'])
            ->where('account_id', '=', $from['id'])
            ->whereIn('type', $allowed_types)
            ->update(['account_id' => $to['id']]);
    }

    public function updateOrCreate($update_vars, PaymentAddress $payment_address, Account $account) {
        $updated_model = $this->updateIfExists($update_vars);
        if ($updated_model) {
            return $updated_model;
        }

        return $this->create($payment_address, $account, $update_vars);
    }

    public function findAll() {
        $query = $this->prototype_model->newQuery();
        return $query->get();
    }

}
