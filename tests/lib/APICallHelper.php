<?php

use App\Models\APICall;
use App\Models\Bot;
use App\Models\User;
use App\Repositories\APICallRepository;

class APICallHelper  {

    function __construct(APICallRepository $account_repository) {
        $this->account_repository = $account_repository;
    }


    public function sampleAPICallVars() {
        return [
            'details' => [
                'method' => 'transferstuff',
                'args'   => [
                    'from' => 'mysource',
                    'to'   => 'mydest'
                ],
            ],
        ];
    }


    // creates a bot
    //   directly in the repository (no validation)
    public function newSampleAPICall(User $user=null, $ledger_entry_vars=[]) {
        $attributes = array_replace_recursive($this->sampleAPICallVars(), $ledger_entry_vars);

        if ($user === null) { $user = app('UserHelper')->getSampleUser(); }

        if (!isset($attributes['user_id'])) { $attributes['user_id'] = $user['id']; }

        $account_model = $this->account_repository->create($attributes);
        return $account_model;
    }




}
