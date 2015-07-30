<?php

use App\Models\LedgerEntry;
use App\Models\User;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\LedgerEntryRepository;
use App\Repositories\PaymentAddressRepository;

/**
*  PaymentAddressHelper
*/
class PaymentAddressHelper
{

    function __construct(PaymentAddressRepository $payment_address_repository, LedgerEntryRepository $ledger_entry_repository) {
        // $this->app = $app;
        $this->payment_address_repository = $payment_address_repository;
        $this->ledger_entry_repository    = $ledger_entry_repository;
    }


    public function createSamplePaymentAddressWithoutDefaultAccount($user=null, $override_vars=[]) {
        if ($user === null) { $user = app('UserHelper')->getSampleUser(); }
        $new_address = $this->payment_address_repository->createWithUser($user, $this->sampleVars($override_vars));
        return $new_address;
    }

    public function createSamplePaymentAddressWithoutInitialBalances($user=null, $override_vars=[]) {
        return $this->createSamplePaymentAddress($user, $override_vars, false);
    }

    public function createSamplePaymentAddress($user=null, $override_vars=[], $initial_balances=null) {
        $new_address = $this->createSamplePaymentAddressWithoutDefaultAccount($user, $override_vars);

        // also create a default account for this address
        AccountHandler::createDefaultAccount($new_address);

        // add initial balances
        if ($initial_balances === null) { $initial_balances = ['TOKENLY' => 100, 'BTC' => 1]; }
        if ($initial_balances) {
            $default_account = AccountHandler::getAccount($new_address, 'default');
            $txid = 'SAMPLE01';
            foreach($initial_balances as $asset => $quantity) {
                $this->ledger_entry_repository->addCredit($quantity, $asset, $default_account, LedgerEntry::CONFIRMED, $txid);
            }
        }

        return $new_address;
    }

    public function sampleVars($override_vars=[]) {
        return array_merge([
            'address'           => '17YdDTY9pjcrAKSZ2AnGS5reXSLhKhxfbh',
            'private_key_token' => 'ASAMPLEKEYTOKEN',
            'user_id'           => 1,
        ], $override_vars);
    }


}