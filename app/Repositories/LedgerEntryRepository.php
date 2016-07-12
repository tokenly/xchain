<?php

namespace App\Repositories;

use App\Models\APICall;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\PaymentAddress;
use App\Models\User;
use App\Providers\Accounts\Exception\AccountException;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Repositories\APIRepository;

/*
* LedgerEntryRepository
*/
class LedgerEntryRepository extends APIRepository
{

    protected $model_type = 'App\Models\LedgerEntry';


    public function create($attributes) {
        if (!isset($attributes['account_id']) OR !$attributes['account_id']) { throw new Exception("Account ID is required", 1); }
        if (!isset($attributes['direction']) OR !is_numeric($attributes['direction'])) { throw new Exception("direction is required", 1); }

        // require either an api call or a transaction
        if (
            (!isset($attributes['api_call_id']) OR !$attributes['api_call_id'])
            AND (!isset($attributes['txid']) OR !$attributes['txid'])
        ) {
            throw new Exception("API Call ID or Transaction ID is required", 1);
        }

        if (!isset($attributes['type']) OR !$attributes['type']) { throw new Exception("Type is required", 1); }

        return parent::create($attributes);
    }

    public function addCredit($float_amount, $asset, Account $account, $type, $direction, $txid, APICall $api_call=null) {
        if ($float_amount < 0) { throw new Exception("Credits must be a positive number", 1); }
        // check type
        LedgerEntry::typeIntegerToString($type);

        return $this->addEntryForAccount($float_amount, $asset, $account, $type, $direction, $txid, $api_call ? $api_call['id'] : null);
    }

    public function addDebit($float_amount, $asset, Account $account, $type, $direction, $txid, APICall $api_call=null) {
        if ($float_amount < 0) { throw new Exception("Debits must be a positive number", 1); }
        // check type
        LedgerEntry::validateTypeInteger($type);

        // begin consistent read transaction
        return DB::transaction(function() use ($float_amount, $asset, $account, $type, $direction, $txid, $api_call) {
            // ensure sufficient asset balance in account
            $existing_balance = $this->accountBalance($account, $asset, $type, true);
            if ($existing_balance <= 0 OR $existing_balance < CurrencyUtil::valueToSatoshis($float_amount)) {
                throw new AccountException('ERR_INSUFFICIENT_FUNDS', 400, "Balance of ".CurrencyUtil::satoshisToValue($existing_balance)." was insufficient to debit $float_amount (".LedgerEntry::typeIntegerToString($type).") $asset from {$account['name']}");
            }

            return $this->addEntryForAccount(0 - $float_amount, $asset, $account, $type, $direction, $txid, $api_call ? $api_call['id'] : null);
        });
    }


    public function transfer($float_amount, $asset, Account $from_account, Account $to_account, $type, $txid=null, APICall $api_call=null) {
        if ($float_amount < 0) { throw new Exception("Transfers must be a positive number", 1); }

        // unconfirmed transfers require a txid reference
        if (($type == LedgerEntry::UNCONFIRMED OR $type == LedgerEntry::SENDING) AND !$txid) { throw new Exception("Unconfirmed funds require a transaction id", 1); }

        // check type
        LedgerEntry::typeIntegerToString($type);

        return DB::transaction(function() use ($float_amount, $asset, $from_account, $to_account, $type, $txid, $api_call) {
            // transfer
            $direction = LedgerEntry::DIRECTION_OTHER;
            $this->addDebit($float_amount, $asset, $from_account, $type, $direction, $txid, $api_call);
            return $this->addCredit($float_amount, $asset, $to_account, $type, $direction, $txid, $api_call);
        });
    }

    public function changeType($float_amount, $asset, Account $account, $from_type, $to_type, $direction, $txid) {
        if ($float_amount < 0) { throw new Exception("Transfers must be a positive number", 1); }

        // check types
        LedgerEntry::validateTypeInteger($from_type);
        LedgerEntry::validateTypeInteger($to_type);

        return DB::transaction(function() use ($float_amount, $asset, $account, $from_type, $to_type, $direction, $txid) {
            // transfer
            $this->addDebit(        $float_amount, $asset, $account, $from_type, $direction, $txid);
            return $this->addCredit($float_amount, $asset, $account, $to_type,   $direction, $txid);
        });
    }



    public function findByAccount(Account $account, $type=null) {
        return $this->findByAccountId($account['id'], $type);
    }

    public function findByAccountId($account_id, $type=null) {
        $query = $this->prototype_model
            ->where('account_id', $account_id)
            ->orderBy('id');

        if ($type !== null) {
            $query->where('type', LedgerEntry::validateTypeInteger($type));
        }

        return $query->get();
    }

    public function deleteByAccount(Account $account) {
        return $this->deleteByAccountId($account['id']);
    }

    public function deleteByAccountId($account_id) {
        return $this->prototype_model
            ->where('account_id', $account_id)
            ->delete();
    }

    public function accountBalance(Account $account, $asset, $type, $in_satoshis=false) {
        $account_id = $account['id'];

        $query = $this->prototype_model
            ->where('account_id', $account_id)
            ->where('asset', $asset);

        if ($type !== null) {
            $query->where('type', LedgerEntry::validateTypeInteger($type));
        }

        $satoshis_sum = $query->sum('amount');

        if ($in_satoshis) { return $satoshis_sum; }

        return CurrencyUtil::satoshisToValue($satoshis_sum);
    }

    // if type is not specified:
    // [
    //   'unconfirmed' => [
    //      BTC => 0.05
    //   ],
    //   'confirmed' => [
    //      BTC = 0.12
    //      SOUP => 10
    //   ],
    //   'sending' => []
    // ]
    // 
    // if type is specified, then just
    // [
    //    BTC = 0.12
    //    SOUP => 10
    // ]
    public function accountBalancesByAsset(Account $account, $type, $in_satoshis=false) {
        $account_id = $account['id'];

        $query = $this->prototype_model
            ->where('account_id', $account_id)
            ->groupBy('asset', 'type')
            ->select('asset', 'type', DB::raw('SUM(amount) AS total_amount') );

        if ($type !== null) {
            $query->where('type', LedgerEntry::validateTypeInteger($type));
        }

        $results = $query->get();

        $sums = $this->assembleAccountBalances($results, $in_satoshis);

        if ($type !== null) {
            return $sums[LedgerEntry::typeIntegerToString($type)];
        }

        return $sums;
    }


    public function combinedAccountBalancesByAsset(PaymentAddress $payment_address, $type, $in_satoshis=false) {
        $query = $this->prototype_model
            ->where('payment_address_id', '=', $payment_address['id'])
            ->groupBy('asset', 'type')
            ->select('asset', 'type', DB::raw('SUM(amount) AS total_amount') );

        if ($type !== null) {
            $query->where('type', LedgerEntry::validateTypeInteger($type));
        }

        $results = $query->get();

        $sums = $this->assembleAccountBalances($results, $in_satoshis);

        if ($type !== null) {
            return $sums[LedgerEntry::typeIntegerToString($type)];
        }


        return $sums;
    }


    // returns [
    //   'account_id1' => [
    //        'BTC'     => 0.001,
    //        'LTBCOIN' => 500,
    //   ],
    //   'account_id2' => [
    //        'BTC'     => 0.032,
    //        'LTBCOIN' => 900,
    //   ],
    // ]
    public function accountBalancesByTXID($txid, $type, $in_satoshis=false) {
        $query = $this->prototype_model
            ->where('txid', $txid)
            ->groupBy('account_id', 'asset')
            ->select('account_id', 'asset', DB::raw('SUM(amount) AS total_amount') );

        // add type
        $query->where('type', LedgerEntry::validateTypeInteger($type));

        $results = $query->get();

        $sums = [];
        foreach($results as $result) {
            if ($in_satoshis) {
                $value = $result['total_amount'];
            } else {
                $value = CurrencyUtil::satoshisToValue($result['total_amount']);
            }

            $sums[$result['account_id']][$result['asset']] = $value;
        }

        return $sums;
    }

    public function lastEntryTypeByTXID($txid) {
        $result = $this->prototype_model
            ->where('txid', $txid)
            ->orderBy('id', 'desc')
            ->select('type' )->first();

        return $result ? $result['type'] : null;
    }

    public function update(Model $model, $attributes) { throw new Exception("Updates are not allowed", 1); }


    public function findByTXID($txid, $payment_address_id=null, $type=null, $direction=null) {
        $query = $this->prototype_model
            ->where('txid', $txid)
            ->orderBy('id');

        if ($payment_address_id !== null) {
            $query->where('payment_address_id', $payment_address_id);
        }

        if ($type !== null) {
            // check type
            $query->where('type', LedgerEntry::validateTypeInteger($type));
        }

        if ($direction !== null) {
            // check direction
            $query->where('direction', LedgerEntry::validateDirectionInteger($direction));
        }

        return $query->get();
    }

    public function deleteByTXID($txid, $payment_address_id=null, $type=null) {
        $query = $this->prototype_model
            ->where('txid', $txid);

        if ($payment_address_id !== null) {
            $query->where('payment_address_id', $payment_address_id);
        }

        if ($type !== null) {
            // check type
            $query->where('type', LedgerEntry::validateTypeInteger($type));
        }

        return $query->delete();
    }

    public function findUnreconciledTransactionEntries(Account $account, $type=null) {
        $account_id = $account['id'];

        if ($type === null) {
            $types = [LedgerEntry::UNCONFIRMED, LedgerEntry::SENDING, ];
        } else {
            $types = [LedgerEntry::validateTypeInteger($type)];
        }

        $query = $this->prototype_model
            ->where('account_id', $account_id)
            ->whereIn('type', $types)
            ->groupBy('txid', 'type')
            ->havingRaw('SUM(amount) != 0')
            ->select('txid', 'type', DB::raw('SUM(amount) AS total') );

        return 
            $query->get();
    }

    ////////////////////////////////////////////////////////////////////////
    
    protected function addEntryForAccount($float_amount, $asset, Account $account, $type, $direction, $txid=null, $api_call_id=null) {
        $create_vars = [
            'payment_address_id' => $account['payment_address_id'],
            'account_id'         => $account['id'],
            'type'               => $type,
            'direction'          => $direction,
            'txid'               => $txid,
            'api_call_id'        => $api_call_id,

            'amount'             => CurrencyUtil::valueToSatoshis($float_amount),
            'asset'              => $asset,
        ];

        return $this->create($create_vars);
    }

    protected function assembleAccountBalances($results, $in_satoshis=false) {
        $sums = array_fill_keys(LedgerEntry::allTypeStrings(), []);

        foreach($results as $result) {
            if ($in_satoshis) {
                $sums[LedgerEntry::typeIntegerToString($result['type'])][$result['asset']] = $result['total_amount'];
            } else {
                $sums[LedgerEntry::typeIntegerToString($result['type'])][$result['asset']] = CurrencyUtil::satoshisToValue($result['total_amount']);
            }
        }

        return $sums;
    }

    protected function assembleAccountBalancesWithTXID($results, $in_satoshis=false) {
        $sums = array_fill_keys(LedgerEntry::allTypeStrings(), []);

        foreach($results as $result) {
            $txid = $result['txid'];
            if (!$txid) { $txid = 'none'; }
            if ($in_satoshis) {
                $sums[LedgerEntry::typeIntegerToString($result['type'])][$txid][$result['asset']] = $result['total_amount'];
            } else {
                $sums[LedgerEntry::typeIntegerToString($result['type'])][$txid][$result['asset']] = CurrencyUtil::satoshisToValue($result['total_amount']);
            }
        }

        return $sums;
    }


}
