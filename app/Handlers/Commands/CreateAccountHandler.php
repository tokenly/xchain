<?php

namespace App\Handlers\Commands;

use App\Commands\CreateAccount;
use App\Repositories\AccountRepository;
use Illuminate\Queue\InteractsWithQueue;
use Tokenly\LaravelEventLog\Facade\EventLog;

class CreateAccountHandler
{
    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct(AccountRepository $account_repository)
    {
        $this->account_repository = $account_repository;
    }

    /**
     * Handle the command.
     *
     * @param  CreateAccount  $command
     * @return void
     */
    public function handle(CreateAccount $command)
    {
        $payment_address = $command->payment_address;
        $create_vars = $command->attributes;
        $create_vars['payment_address_id'] = $payment_address['id'];
        $create_vars['user_id'] = $payment_address['user_id'];

        $account = $this->account_repository->create($create_vars);
        EventLog::log('account.created', json_decode(json_encode($account)));
    }
}
