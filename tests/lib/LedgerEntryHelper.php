<?php

use App\Models\Bot;
use App\Models\LedgerEntry;
use App\Repositories\LedgerEntryRepository;

class LedgerEntryHelper  {

    function __construct(LedgerEntryRepository $bot_ledger_entry_repository) {
        $this->bot_ledger_entry_repository = $bot_ledger_entry_repository;
    }


    public function sampleLedgerEntryVars() {
        return [
            'payment_address_id' => null,
            'account_id'           => null,
            'api_call_id'          => null,
            'txid'                 => null,
            'type'                 => LedgerEntry::CONFIRMED,
            'direction'            => LedgerEntry::DIRECTION_RECEIVE,

            'amount'               => 100000000,
            'asset'                => 'BTC',
        ];
    }


    // creates a bot
    //   directly in the repository (no validation)
    public function newSampleLedgerEntry($account=null, $api_call=null, $ledger_entry_vars=[]) {
        $attributes = array_replace_recursive($this->sampleLedgerEntryVars(), $ledger_entry_vars);

        if ($account == null) { $account = app('AccountHelper')->newSampleAccount(); }
        if (!isset($attributes['account_id'])) { $attributes['account_id'] = $account['id']; }
        if (!isset($attributes['payment_address_id'])) { $attributes['payment_address_id'] = $account['payment_address_id']; }

        if (!isset($attributes['api_call']) AND !isset($attributes['txid'])) {
            if ($api_call == null) { $api_call = app('APICallHelper')->newSampleAPICall(); }
            $attributes['api_call_id'] = $api_call['id'];
        }

        $bot_ledger_entry_model = $this->bot_ledger_entry_repository->create($attributes);
        return $bot_ledger_entry_model;
    }




}
