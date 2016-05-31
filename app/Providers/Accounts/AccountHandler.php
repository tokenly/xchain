<?php

namespace App\Providers\Accounts;

use App\Commands\CreateAccount;
use App\Models\APICall;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Providers\Accounts\Exception\AccountException;
use App\Repositories\AccountRepository;
use App\Repositories\LedgerEntryRepository;
use App\Repositories\PaymentAddressRepository;
use Exception;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\RecordLock\Facade\RecordLock;

class AccountHandler {

    const RECEIVED_CONFIRMATIONS_REQUIRED = 2;
    const SEND_CONFIRMATIONS_REQUIRED     = 1;

    const SEND_LOCK_TIMEOUT = 300; // 5 minutes

    use DispatchesCommands;

    function __construct(PaymentAddressRepository $payment_address_repository, AccountRepository $account_repository, LedgerEntryRepository $ledger_entry_repository) {
        $this->account_repository         = $account_repository;
        $this->ledger_entry_repository    = $ledger_entry_repository;
        $this->payment_address_repository = $payment_address_repository;
    }

    public function createDefaultAccount(PaymentAddress $payment_address) {
        $this->createAccount($payment_address, 'default');
    }

    public function createAccount(PaymentAddress $payment_address, $name) {
        // also create a default account for this address
        $this->dispatch(new CreateAccount(['name' => $name], $payment_address));

        return $this->getAccount($payment_address, $name);
    }

    public function receive(PaymentAddress $payment_address, $quantity, $asset, $parsed_tx, $confirmations) {
        // when migrating, we need to ignore the transactions already confirmed
        if ($confirmations > 0 AND $parsed_tx['bitcoinTx']['blockheight'] < Config::get('xchain.accountsIgnoreBeforeBlockHeight')) {
            EventLog::log('account.receive.ignored', ['blockheight' => $parsed_tx['bitcoinTx']['blockheight'], 'confirmations' => $confirmations, 'ignoredBefore' => Config::get('xchain.accountsIgnoreBeforeBlockHeight')]);
            return;
        }


        return RecordLock::acquireAndExecute($payment_address['uuid'], function() use ($payment_address, $quantity, $asset, $parsed_tx, $confirmations) {
            DB::transaction(function() use ($payment_address, $quantity, $asset, $parsed_tx, $confirmations) {
                // get the default account
                $default_account = $this->getAccount($payment_address);

                list($txid, $dust_size) = $this->extractDataFromParsedTransaction($parsed_tx);

                // Log::debug("receive $quantity $asset \$txid=$txid \$confirmations=".json_encode($confirmations, 192));
                if ($confirmations >= self::RECEIVED_CONFIRMATIONS_REQUIRED) {
                    // confirmed receive
                    $type = LedgerEntry::CONFIRMED;

                    // if there are any confirmed entries for this txid already, then 
                    //  don't add anything new
                    $existing_ledger_entries = $this->ledger_entry_repository->findByTXID($txid, $payment_address['id'], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_RECEIVE);
                    if (count($existing_ledger_entries) > 0) { return; }

                    // find the funds and move each one to the confirmed status
                    $any_unconfirmed_funds_found = false;
                    $unconfirmed_balances_for_txid_by_account_id = $this->ledger_entry_repository->accountBalancesByTXID($txid, LedgerEntry::UNCONFIRMED);
                    foreach($unconfirmed_balances_for_txid_by_account_id as $account_id => $balances_for_txid) {
                        $account = $this->account_repository->findByID($account_id);

                        // the account must belong to the payment address
                        if ($account['payment_address_id'] != $payment_address['id']) { continue; }

                        // get the full unconfirmed amount (which might be less than the amount by txid)
                        $unfiltered_unconfirmed_balances = $this->ledger_entry_repository->accountBalancesByAsset($account, LedgerEntry::UNCONFIRMED);

                        $any_unconfirmed_funds_found = true;
                        foreach($balances_for_txid as $unconfirmed_txid_asset => $unconfirmed_txid_quantity) {
                            if ($unconfirmed_txid_quantity > 0) {
                                $unconfirmed_amount_to_move = $unconfirmed_txid_quantity;
                                $unconfirmed_funds_already_sent = 0;
                                if (isset($unfiltered_unconfirmed_balances[$unconfirmed_txid_asset])) {
                                    if ($unconfirmed_amount_to_move > $unfiltered_unconfirmed_balances[$unconfirmed_txid_asset]) {
                                        $unconfirmed_amount_to_move = $unfiltered_unconfirmed_balances[$unconfirmed_txid_asset];
                                        // the rest have already been sent
                                        $unconfirmed_funds_already_sent = $unconfirmed_txid_quantity - $unconfirmed_amount_to_move;
                                    }
                                }

                                // start by moving all the unconfirmed funds we can
                                $this->ledger_entry_repository->changeType($unconfirmed_amount_to_move, $unconfirmed_txid_asset, $account, LedgerEntry::UNCONFIRMED, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_RECEIVE, $txid);

                                if ($unconfirmed_funds_already_sent > 0) {
                                    // these funds were already moved from unconfirmed to sent
                                    EventLog::log('account.receive.unconfirmedAlreadySent', ['address' => $payment_address['uuid'], 'quantity' => $unconfirmed_funds_already_sent, 'asset' => $unconfirmed_txid_asset, 'txid' => $txid, ]);
                                }
                            }
                        }
                    }

                    // handle a situation where unconfirmed funds were not already added
                    if (!$any_unconfirmed_funds_found) {
                        // credit the asset(s) including the BTC dust
                        foreach ($this->buildReceiveBalances($quantity, $asset, $dust_size) as $asset_received => $quantity_received) {
                            $this->ledger_entry_repository->addCredit($quantity_received, $asset_received, $default_account, $type, LedgerEntry::DIRECTION_RECEIVE, $txid);
                        }
                    }


                } else {
                    // unconfirmed receive

                    // if there are any entries for this txid and type already, then 
                    //  don't add anything new
                    $existing_ledger_entries = $this->ledger_entry_repository->findByTXID($txid, $payment_address['id'], null, LedgerEntry::DIRECTION_RECEIVE);
                    if (count($existing_ledger_entries) > 0) {
                        if ($confirmations == 0) { EventLog::log('account.receive.alreadyRecorded', ['txid' => $txid, 'type' => 'unconfirmed', 'existingLedgerEntries' => count($existing_ledger_entries)]); }
                        // Log::debug("all ledger entries for payment address: ".json_encode($this->ledger_entry_repository->findByTXID($txid, $payment_address['id']), 192));
                        return;
                    }

                    // credit the asset(s) including the BTC dust
                    foreach ($this->buildReceiveBalances($quantity, $asset, $dust_size) as $asset_received => $quantity_received) {
                        $this->ledger_entry_repository->addCredit($quantity_received, $asset_received, $default_account, LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_RECEIVE, $txid);
                    }
                }


            });
        }, self::SEND_LOCK_TIMEOUT);
    }

    public function send(PaymentAddress $payment_address, $quantity, $asset, $parsed_tx, $confirmations) {
        // when migrating, we need to ignore the transactions already confirmed
        if ($confirmations > 0 AND $parsed_tx['bitcoinTx']['blockheight'] < Config::get('xchain.accountsIgnoreBeforeBlockHeight')) {
            EventLog::log('account.receive.ignored', ['blockheight' => $parsed_tx['bitcoinTx']['blockheight'], 'confirmations' => $confirmations, 'ignoredBefore' => Config::get('xchain.accountsIgnoreBeforeBlockHeight')]);
            return;
        }

        return RecordLock::acquireAndExecute($payment_address['uuid'], function() use ($payment_address, $quantity, $asset, $parsed_tx, $confirmations) {
            DB::transaction(function() use ($payment_address, $quantity, $asset, $parsed_tx, $confirmations) {

                list($txid, $dust_size, $btc_fees) = $this->extractDataFromParsedTransaction($parsed_tx);
                Log::debug("send: $txid, $dust_size, $btc_fees  \$confirmations=$confirmations");

                if ($confirmations >= self::SEND_CONFIRMATIONS_REQUIRED) {
                    // confirmed send
                    $any_sending_funds_found = $this->debitFundsFromSendingLedgerByTXID($payment_address, $txid);

                    if (!$any_sending_funds_found) {
                        // the funds were not marked as sending yet - so do that now and then immediately debit them from sending
                        $this->moveConfimedFundsToSendingLedgerByTXID($payment_address, $txid, $quantity, $asset, $btc_fees, $dust_size);
                        $this->debitFundsFromSendingLedgerByTXID($payment_address, $txid);
                    }

                } else {
                    // unconfirmed send
                    $this->moveConfimedFundsToSendingLedgerByTXID($payment_address, $txid, $quantity, $asset, $btc_fees, $dust_size);
                }

            });
        }, self::SEND_LOCK_TIMEOUT);
    }

    public function invalidate($parsed_tx) {
        DB::transaction(function() use ($parsed_tx) {
            $txid = $parsed_tx['txid'];

            $existing_ledger_entries = $this->ledger_entry_repository->findByTXID($txid, null, null, null);
            if (count($existing_ledger_entries) == 0) { return; }

            // get all payment addresses
            $payment_addresses_by_id = [];
            foreach($existing_ledger_entries as $existing_ledger_entry) {
                $payment_address_id = $existing_ledger_entry['payment_address_id'];
                if (isset($payment_addresses_by_id[$payment_address_id])) { continue; }

                $payment_addresses_by_id[$payment_address_id] = $this->payment_address_repository->findByID($payment_address_id);
            }
            Log::debug("invalidate txid: $txid.  \$payment_addresses_by_id=".count($payment_addresses_by_id));

            // process each payment address in a locked state
            //   and delete all ledger entries for this txid
            foreach($payment_addresses_by_id as $payment_address) {
                RecordLock::acquireAndExecute($payment_address['uuid'], function() use ($payment_address, $txid) {
                    $this->ledger_entry_repository->deleteByTXID($txid, $payment_address['id']);
                }, self::SEND_LOCK_TIMEOUT);
            }
        });
    }


    // this method assumes the account is already locked
    public function markAccountFundsAsSending(Account $account, $quantity, $asset, $float_fee, $dust_size, $txid) {
        $balances_sent = $this->buildSendBalances($quantity, $asset, $float_fee, $dust_size);
        foreach($balances_sent as $asset_to_send => $confirmed_and_unconfirmed_quantity_to_send) {
            // try confirmed first
            $confirmed_quantity_available = $this->ledger_entry_repository->accountBalance($account, $asset_to_send, LedgerEntry::CONFIRMED);
            $confirmed_quantity_to_send = min($confirmed_quantity_available, $confirmed_and_unconfirmed_quantity_to_send);
            if ($confirmed_quantity_to_send > 0) {
                $this->ledger_entry_repository->changeType($confirmed_quantity_to_send, $asset_to_send, $account, LedgerEntry::CONFIRMED, LedgerEntry::SENDING, LedgerEntry::DIRECTION_SEND, $txid);
            }

            // if any leftover, then do unconfirmed
            if ($confirmed_quantity_to_send < $confirmed_and_unconfirmed_quantity_to_send) {
                $unconfirmed_quantity_to_send = $confirmed_and_unconfirmed_quantity_to_send - $confirmed_quantity_to_send;
                if ($unconfirmed_quantity_to_send > 0) {
                    // Log::debug("\$unconfirmed_quantity_to_send=".json_encode($unconfirmed_quantity_to_send, 192));
                    $this->ledger_entry_repository->changeType($unconfirmed_quantity_to_send, $asset_to_send, $account, LedgerEntry::UNCONFIRMED, LedgerEntry::SENDING, LedgerEntry::DIRECTION_SEND, $txid);
                }
            }

        }
    }

    // this method assumes the account is already locked
    public function markConfirmedAccountFundsAsSending(Account $account, $quantity, $asset, $float_fee, $dust_size, $txid) {
        $balances_sent = $this->buildSendBalances($quantity, $asset, $float_fee, $dust_size);
        foreach($balances_sent as $sent_asset => $sent_quantity) {
            // Log::debug("changeType $sent_quantity $sent_asset to SENDING with txid $txid");
            $this->ledger_entry_repository->changeType($sent_quantity, $sent_asset, $account, LedgerEntry::CONFIRMED, LedgerEntry::SENDING, LedgerEntry::DIRECTION_SEND, $txid);
        }
    }


    public function transfer(PaymentAddress $payment_address, $from, $to, $quantity, $asset, $txid=null, APICall $api_call=null) {
        return RecordLock::acquireAndExecute($payment_address['uuid'], function() use ($payment_address, $from, $to, $quantity, $asset, $txid, $api_call) {
            return DB::transaction(function() use ($payment_address, $from, $to, $quantity, $asset, $txid, $api_call) {
                // from account
                $from_account = $this->account_repository->findByName($from, $payment_address['id']);
                if (!$from_account) { throw new AccountException('ERR_FROM_ACCOUNT_NOT_FOUND', 404, "unable to find `from` account"); }

                // to account
                $to_account = $this->getDestinationAccount($to, $payment_address);

                // get a (valid) type
                if ($txid === null) {
                    $type = LedgerEntry::CONFIRMED;
                } else {
                    $type = $this->determineTypeFromTXID($txid);

                    // ensure sufficient funds for this txid
                    $balances_by_account_id = $this->ledger_entry_repository->accountBalancesByTXID($txid, $type);
                    $has_sufficient_funds = false;
                    if (isset($balances_by_account_id[$from_account['id']])) {
                        $balances = $balances_by_account_id[$from_account['id']];
                        if ($balances AND isset($balances[$asset]) AND $balances[$asset] >= $quantity) {
                            $has_sufficient_funds = true;
                        }
                    }

                    if (!$has_sufficient_funds) { throw new AccountException('ERR_INSUFFICIENT_FUNDS', 400, "This account does not have sufficient funds for this transaction id."); }
                }


                // quantity and asset
                $this->ledger_entry_repository->transfer($quantity, $asset, $from_account, $to_account, $type, $txid, $api_call);

                // done
                return;
            });
        }, self::SEND_LOCK_TIMEOUT);
    }


    public function transferAllByTIXD(PaymentAddress $payment_address, $from, $to, $txid, APICall $api_call=null) {
        return RecordLock::acquireAndExecute($payment_address['uuid'], function() use ($payment_address, $from, $to, $txid, $api_call) {
            return DB::transaction(function() use ($payment_address, $from, $to, $txid, $api_call) {
                // from account
                $from_account = $this->account_repository->findByName($from, $payment_address['id']);
                if (!$from_account) { throw new AccountException('ERR_FROM_ACCOUNT_NOT_FOUND', 404, "Unable to find `from` account"); }

                // to account
                $to_account = $this->getDestinationAccount($to, $payment_address);

                // get a (valid) type
                $type = $this->determineTypeFromTXID($txid);

                // get all funds for this txid
                $balances_by_account_id = $this->ledger_entry_repository->accountBalancesByTXID($txid, $type);
                $any_found = false;
                if (isset($balances_by_account_id[$from_account['id']])) {
                    $balances = $balances_by_account_id[$from_account['id']];
                    foreach($balances as $asset => $quantity) {
                        // quantity and asset
                        // Log::debug("Transfer $quantity $asset from {$from_account['name']} to {$to_account['name']}");
                        $this->ledger_entry_repository->transfer($quantity, $asset, $from_account, $to_account, $type, $txid, $api_call);
                        $any_found = true;
                    }
                }

                // if no balances were transfered, return an error
                if (!$any_found) { throw new AccountException('ERR_NO_BALANCE', 404, "No balances in `from` account were found for this transaction ID"); }

                // done
                return;
            });
        }, self::SEND_LOCK_TIMEOUT);
    }


    public function close(PaymentAddress $payment_address, $from, $to, APICall $api_call) {
        RecordLock::acquireAndExecute($payment_address['uuid'], function() use ($payment_address, $from, $to, $api_call) {
            return DB::transaction(function() use ($payment_address, $from, $to, $api_call) {
                // from account
                $from_account = $this->account_repository->findByName($from, $payment_address['id']);
                if (!$from_account) { throw new AccountException('ERR_FROM_ACCOUNT_NOT_FOUND', 404, "unable to find `from` account"); }

                // check for unconfirmed or sending funds
                $all_balances_by_asset = $this->ledger_entry_repository->accountBalancesByAsset($from_account, null);
                if (isset($all_balances_by_asset['unconfirmed'])) {
                    foreach ($all_balances_by_asset['unconfirmed'] as $asset => $quantity) {
                        if ($quantity > 0) { throw new AccountException('ERR_ACCOUNT_HAS_UNCONFIRMED_FUNDS', 400, "Cannot close an account with unconfirmed funds"); }
                    }
                }
                if (isset($all_balances_by_asset['sending'])) {
                    foreach ($all_balances_by_asset['sending'] as $asset => $quantity) {
                        if ($quantity > 0) { throw new AccountException('ERR_ACCOUNT_HAS_SENDING_FUNDS', 400, "Cannot close an account with sending funds"); }
                    }
                }

                // to account
                $to_account = $this->account_repository->findByName($to, $payment_address['id']);
                if (!$to_account) {
                    // create one
                    $to_account = $this->account_repository->create([
                        'name'                 => $to,
                        'payment_address_id' => $payment_address['id'],
                        'user_id'              => $payment_address['user_id'],
                    ]);
                }

                // move all balances
                //  accountBalancesByAsset
                foreach($all_balances_by_asset as $type_string => $balances) {
                    $type = LedgerEntry::typeStringToInteger($type_string);
                    $txid = null;
                    foreach($balances as $asset => $quantity) {
                        if ($quantity < 0) { throw new Exception("Attempt to transfer negative quantity for account {$from_account['name']} ({$from_account['uuid']})", 1); }
                        if ($quantity > 0) {
                            $this->ledger_entry_repository->transfer($quantity, $asset, $from_account, $to_account, $type, $txid, $api_call);
                        }
                    }
                }

                // close the account
                $this->account_repository->update($from_account, ['active' => false]);

                // done
                return;
            });
        }, self::SEND_LOCK_TIMEOUT);
    }

    public function acquirePaymentAddressLock(PaymentAddress $payment_address, $timeout=1800) {
        return RecordLock::acquire($payment_address['uuid'], $timeout);
    }

    public function releasePaymentAddressLock(PaymentAddress $payment_address) {
        return RecordLock::release($payment_address['uuid']);
    }

    public function getAccount(PaymentAddress $payment_address, $name='default') {
        return $this->account_repository->findByName($name, $payment_address['id']);
    }

    public function accountHasSufficientConfirmedFunds(Account $account, $quantity, $asset, $float_fee, $dust_size) {
        $actual_balances = $this->ledger_entry_repository->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        return $this->hasSufficientFunds($actual_balances, $quantity, $asset, $float_fee, $dust_size);
    }

    public function accountHasSufficientFunds(Account $account, $quantity, $asset, $float_fee, $dust_size) {
        $confirmed_actual_balances = $this->ledger_entry_repository->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        $unconfirmed_actual_balances = $this->ledger_entry_repository->accountBalancesByAsset($account, LedgerEntry::UNCONFIRMED);
        $actual_balances = $confirmed_actual_balances;
        foreach($unconfirmed_actual_balances as $unconfirmed_asset => $unconfirmed_quantity) {
            if (!isset($actual_balances[$unconfirmed_asset])) { $actual_balances[$unconfirmed_asset] = 0.0; }
            $actual_balances[$unconfirmed_asset] += $unconfirmed_quantity;
        }
        // Log::debug("\$actual_balances=".json_encode($actual_balances, 192));
        // Log::debug("\$unconfirmed_actual_balances=".json_encode($unconfirmed_actual_balances, 192));
        // Log::debug("\$confirmed_actual_balances=".json_encode($confirmed_actual_balances, 192));

        return $this->hasSufficientFunds($actual_balances, $quantity, $asset, $float_fee, $dust_size);
    }

    public function zeroAllBalances(PaymentAddress $payment_address, APICall $api_call) {
        $txid = null;
        foreach ($this->account_repository->findByAddressAndUserID($payment_address['id'], $payment_address['user_id']) as $account) {
            $actual_balances_by_type = $this->ledger_entry_repository->accountBalancesByAsset($account, null);
            foreach($actual_balances_by_type as $type_string => $actual_balances) {
                if ($type_string == 'sending') { continue; }
                foreach($actual_balances as $asset => $quantity) {
                    if ($quantity <= 0) { continue; }
                    $this->ledger_entry_repository->addDebit($quantity, $asset, $account, LedgerEntry::typeStringToInteger($type_string), LedgerEntry::DIRECTION_OTHER, $txid, $api_call);
                }
            }
        }
    }

    ////////////////////////////////////////////////////////////////////////

    protected function hasSufficientFunds($actual_balances, $quantity, $asset, $float_fee, $dust_size) {
        // build required balances with fees
        $balances_required = $this->buildSendBalances($quantity, $asset, $float_fee, $dust_size);
        // Log::debug("hasSufficientFunds quantity=$quantity asset=$asset) \$balances_required=".json_encode($balances_required, 192));

        // check actual balances
        $has_sufficient_funds = true;
        foreach($balances_required as $asset_required => $quantity_required) {
            if (!isset($actual_balances[$asset_required]) OR CurrencyUtil::valueToSatoshis($actual_balances[$asset_required]) < CurrencyUtil::valueToSatoshis($quantity_required)) {
                $has_sufficient_funds = false;
            }
        }

        return $has_sufficient_funds;
    }

    protected function buildSendBalances($quantity, $asset, $float_fee, $dust_size) {
        $send_balances = [$asset => $quantity];
        if ($asset != 'BTC') { $send_balances['BTC'] = $dust_size; }
        $send_balances['BTC'] = $send_balances['BTC'] + $float_fee;
        return $send_balances;
    }

    protected function buildReceiveBalances($quantity, $asset, $dust_size) {
        $receive_balances = [$asset => $quantity];
        if ($asset != 'BTC' AND $dust_size > 0) {
            $receive_balances['BTC'] = $dust_size;
        }
        return $receive_balances;
    }


    protected function extractDataFromParsedTransaction($parsed_tx) {
        $txid = $parsed_tx['txid'];
        $dust_size = 0;
        if ($parsed_tx['network'] == 'counterparty') {
            $dust_size = $parsed_tx['counterpartyTx']['dustSize'];
        }
        if (isset($parsed_tx['bitcoinTx']['fees'])) {
            $fee = $parsed_tx['bitcoinTx']['fees'];
        } else {
            Log::warning("no fees found in bitcoinTx for parsed tx: ".json_encode($parsed_tx, 192));
            $fee = 0;
        }

        return [$txid, $dust_size, $fee];
    }

    protected function determineTypeFromTXID($txid) {
        // get the last credit for this txid and use that type
        return $this->ledger_entry_repository->lastEntryTypeByTXID($txid);
    }


    protected function getDestinationAccount($name, PaymentAddress $payment_address) {
        $destination_account = $this->account_repository->findByName($name, $payment_address['id']);
        if (!$destination_account) {
            // create one
            $destination_account = $this->account_repository->create([
                'name'               => $name,
                'payment_address_id' => $payment_address['id'],
                'user_id'            => $payment_address['user_id'],
            ]);
        }
        return $destination_account;
    }


    protected function debitFundsFromSendingLedgerByTXID($payment_address, $txid) {
        // find any sending funds and debit them
        $any_sending_funds_found = false;
        $sent_balances_by_account_id = $this->ledger_entry_repository->accountBalancesByTXID($txid, LedgerEntry::SENDING);
        // Log::debug("\$sent_balances_by_account_id=".json_encode($sent_balances_by_account_id, 192));
        foreach($sent_balances_by_account_id as $account_id => $balances) {
            $any_sending_funds_found = true;
            $account = $this->account_repository->findByID($account_id);

            // this account must belong to the payment address
            if ($account['payment_address_id'] != $payment_address['id']) { continue; }

            foreach($balances as $asset => $quantity) {
                if ($quantity > 0) {
                    $this->ledger_entry_repository->addDebit($quantity, $asset, $account, LedgerEntry::SENDING, LedgerEntry::DIRECTION_SEND, $txid);
                }
            }
        }

        return $any_sending_funds_found;
    }

    protected function moveConfimedFundsToSendingLedgerByTXID($payment_address, $txid, $quantity, $asset, $btc_fees, $dust_size) {
        // get the default account
        $default_account = $this->getAccount($payment_address);

        // if there are any entries for this txid and payment address and type already, then
        //  don't add anything new
        $existing_ledger_entries = $this->ledger_entry_repository->findByTXID($txid, $payment_address['id'], null, LedgerEntry::DIRECTION_SEND);
        if (count($existing_ledger_entries) > 0) {
            EventLog::log('account.send.alreadyRecorded', ['txid' => $txid, 'existingLedgerEntries' => count($existing_ledger_entries)]);
            return;
        }

        // change type
        foreach ($this->buildSendBalances($quantity, $asset, $btc_fees, $dust_size) as $asset_sent => $quantity_sent) {
            $this->ledger_entry_repository->changeType($quantity_sent, $asset_sent, $default_account, LedgerEntry::CONFIRMED, LedgerEntry::SENDING, LedgerEntry::DIRECTION_SEND, $txid);
        }
    }

}
