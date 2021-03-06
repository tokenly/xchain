<?php

namespace App\Repositories;

use App\Models\ProvisionalTransaction;
use App\Models\Transaction;
use Carbon\Carbon;
use Exception;


/*
* ProvisionalTransactionRepository
*/
class ProvisionalTransactionRepository
{

    protected $model_type = 'App\Models\ProvisionalTransaction';

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
            ->first();
    }

    // public function update(ProvisionalTransaction $model, $attributes) {
    //     return $model->update($attributes);
    // }

    public function delete(ProvisionalTransaction $model) {
        return $model->delete();
    }

    public function deleteOlderThan(Carbon $date) {
        $affected_rows = $this->prototype_model->where('created_at', '<=', $date)->delete();
        return;
    }

    public function deleteAll() {
        return ProvisionalTransaction::truncate();
    }

    public function deleteByTXID($txid) {
        if ($transaction = $this->findByTXID($txid)) {
            return $this->delete($transaction);
        }

        return false;
    }


    public function create(Transaction $transaction) {
        $attributes = [
            'transaction_id' => $transaction['id'],
            'txid'           => $transaction['txid'],
        ];

        return $this->prototype_model->create($attributes);
    }

    public function findAll() {
        $query = $this->prototype_model->newQuery();
        return $query->get();
    }

}
