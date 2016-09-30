<?php

namespace App\Jobs;

use App\Models\PaymentAddress;
use App\Repositories\AccountRepository;
use Illuminate\Queue\InteractsWithQueue;
use Tokenly\LaravelEventLog\Facade\EventLog;

class CreateAccountJob
{

    var $payment_address;
    var $attributes;

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct($attributes, PaymentAddress $payment_address)
    {
        $this->payment_address = $payment_address;
        $this->attributes = $attributes;
    }

    /**
     * Handle the command.
     *
     * @return void
     */
    public function handle(AccountRepository $account_repository)
    {
        $payment_address = $this->payment_address;
        $create_vars = $this->attributes;
        $create_vars['payment_address_id'] = $payment_address['id'];
        $create_vars['user_id'] = $payment_address['user_id'];

        $account = $account_repository->create($create_vars);
        EventLog::log('account.created', json_decode(json_encode($account)));
    }
}
