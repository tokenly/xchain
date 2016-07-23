<?php

use App\Models\Account;
use App\Models\Bot;
use App\Models\PaymentAddress;
use App\Repositories\AccountRepository;

class AccountHelper  {

    function __construct(AccountRepository $account_repository) {
        $this->account_repository = $account_repository;
    }


    public function sampleVars() {
        return [
            'name'   => 'Test Account',
            'meta'   => ['foo' => 'bar'],
            'active' => true,
        ];
    }

    public function sampleVarsForAPI() {
        $sample_vars_for_api = $this->sampleVars();
        return $sample_vars_for_api;

    }


    // creates a bot
    //   directly in the repository (no validation)
    public function newSampleAccount(PaymentAddress $payment_address=null, $account_vars_or_name=[]) {
        $account_vars = $account_vars_or_name;
        if ($account_vars_or_name AND !is_array($account_vars_or_name)) { $account_vars = ['name' => $account_vars_or_name]; }
        $attributes = array_replace_recursive($this->sampleVars(), $account_vars);

        if ($payment_address === null) { $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress(); }

        if (!isset($attributes['payment_address_id'])) { $attributes['payment_address_id'] = $payment_address['id']; }
        if (!isset($attributes['user_id'])) { $attributes['user_id'] = $payment_address['user_id']; }

        $account_model = $this->account_repository->create($attributes);
        return $account_model;
    }




}
