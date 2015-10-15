<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\CreatedAtDateOnly;

class ProvisionalTransaction extends Model {

    use CreatedAtDateOnly;

    protected $table = 'provisional_transactions';

    protected static $unguarded = true;


    public function transaction() {
        return $this->belongsTo('App\Models\Transaction');
    }

}
