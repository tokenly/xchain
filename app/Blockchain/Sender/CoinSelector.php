<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use App\Models\TXO;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class CoinSelector {

    const STATIC_SIZE          = 10;
    const P2PKH_INPUT_SIZE     = 147;
    const P2PKH_OUTPUT_SIZE    = 34;
    const OPRETURN_PREFIX_SIZE = 11;
    
    const DUST_AMOUNT          = 546;

    const MONTE_CARLO_COUNT = 1000;
    const SORT_DESCENDING   = -1;
    const SORT_ASCENDING    = 1;

    // set if using primes
    var $prime_input_size = 0;

    // set if confirmed txos are required
    var $require_confirmed = false;

    // the fee per byte
    var $fee_per_byte = 0;

    // an array of UTXO records
    //   each txo should have: txid, n, amount, type, green
    var $utxos;

    // outputs should be
    //   ["amount": 0]
    var $outputs;


    // the sum of the outputs
    protected $output_amount = 0;

    // op return script size in bytes (not including the script instructions)
    var $op_return_size;

    public function __construct($utxos, $outputs, $fee_per_byte=0, $op_return_size=null) {
        $this->utxos          = $utxos;
        $this->outputs        = $outputs;
        $this->fee_per_byte   = $fee_per_byte;
        $this->output_amount  = $this->sumOutputs($outputs);
        $this->op_return_size = $op_return_size;
    }

    public function setFeePerByte($fee_per_byte) {
        $this->fee_per_byte = $fee_per_byte;
        return $this;
    }

    public function setPrimeInputSize($prime_input_size) {
        $this->prime_input_size = $prime_input_size;
        return $this;
    }

    public function setOpReturnSize($op_return_size) {
        $this->op_return_size = $op_return_size;
        return $this;
    }

    public function requireGreenOrConfirmed() {
        $this->require_confirmed = true;
        return $this;
    }

    /**
     * builds a coin group
     *
     * returns (all amount values are in satoshis)
     * [
     *   'in_amount'     (integer, in satoshis)
     *   'change_amount' (integer, in satoshis)
     *   'fee'           (integer, in satoshis)
     *   'fee_per_byte'  (float, in satoshis)
     *   'size'          (integer)
     *   'txos'          (array)
     * ]
     * 
     * @return array a coin group array
     */
    public function chooseCoins() {
        if ($this->fee_per_byte <= 0) { throw new Exception("Fee per byte must be speficied", 1); }

        $base_tx_size = $this->buildBaseTransactionSize($this->outputs, $this->op_return_size);

        $all_sorted_utxos = $this->utxos;

        // filter primes
        if ($this->prime_input_size > 0) {
            $all_sorted_utxos = $this->excludePrimes($all_sorted_utxos, $this->prime_input_size);
        }

        // biggest to smallest
        $all_sorted_utxos = $this->sortTXOs($all_sorted_utxos, self::SORT_DESCENDING);

        // get confirmed and green UTXOs first
        $source_utxos = $this->filterGreenOrConfirmedUTXOs($all_sorted_utxos);
        $target_coin_group = $this->buildBestCoinGroup($this->output_amount, $source_utxos, $base_tx_size, $this->fee_per_byte);
        if ($target_coin_group) {
            return $target_coin_group;
        }

        if ($this->require_confirmed) {
            // couldn't find any matching sets amongst the confirmed (or green) UTXOs
            return null;
        }

        // fallback to include unconfirmed UTXOs
        $source_utxos = $all_sorted_utxos;
        $target_coin_group = $this->buildBestCoinGroup($this->output_amount, $source_utxos, $base_tx_size, $this->fee_per_byte);
        return $target_coin_group;
    }

    // ------------------------------------------------------------------------

    protected function buildBestCoinGroup($output_amount, $source_utxos, $base_tx_size, $fee_per_byte) {
        $matching_coin_groups = [];

        // go in order from highest to lowest UTXO looking for a combination that will work
        for ($skip_count=0; $skip_count < count($source_utxos); $skip_count++) { 
            $utxos_subset = array_slice($source_utxos, $skip_count);
            $coin_groups = $this->buildTargetCoinGroupFromUTXOs($output_amount, $utxos_subset, $base_tx_size, $fee_per_byte);

            if ($coin_groups) {
                $matching_coin_groups = array_merge($matching_coin_groups, $coin_groups);
            }
        }

        // if we didn't find it by now, we will never find it
        if (!$matching_coin_groups) {
            return [];
        }

        // also try 1000 shuffled combinations
        $utxos_subset = $source_utxos;
        for ($i=0; $i < self::MONTE_CARLO_COUNT; $i++) { 
            shuffle($utxos_subset);
            $coin_groups = $this->buildTargetCoinGroupFromUTXOs($output_amount, $utxos_subset, $base_tx_size, $fee_per_byte);
            if ($coin_groups) {
                $matching_coin_groups = array_merge($matching_coin_groups, $coin_groups);
            }
        }

        // echo "\$matching_coin_groups: ".json_encode($matching_coin_groups, 192)."\n";
        return $this->determinBestMatchingCoinGroup($matching_coin_groups);
    }

    protected function buildTargetCoinGroupFromUTXOs($output_amount, $source_utxos, $base_tx_size, $fee_per_byte) {
        $matching_coin_groups = [];

        // start with the highest utxos and work down
        $txos = [];
        $working_sum = 0;
        foreach($source_utxos as $source_utxo) {
            $working_sum += $source_utxo['amount'];
            $txos[] = $source_utxo;

            if ($working_sum < $output_amount) {
                continue;
            }

            // check with and without change
            $coin_group = $this->buildCoinGroup($working_sum, $output_amount, $fee_per_byte, $base_tx_size, $txos, false);
            if ($coin_group) {
                $matching_coin_groups[] = $coin_group;
            }

            $coin_group = $this->buildCoinGroup($working_sum, $output_amount, $fee_per_byte, $base_tx_size, $txos, true);
            if ($coin_group) {
                $matching_coin_groups[] = $coin_group;
            }

            // find the best group from the two with and without change
            if ($matching_coin_groups) {
                return $matching_coin_groups;
            }
        }

        // no match was found
        return null;
    }

    protected function buildCoinGroup($input_amount, $output_amount, $fee_per_byte, $base_tx_size, $txos, $with_change_output) {
        // check the fee
        $size = $this->buildTransactionSize($base_tx_size, count($txos), $with_change_output);
        $required_fee = $size * $fee_per_byte;

        if ($input_amount >= $output_amount + $required_fee) {
            // calculate the fee
            $fee = $required_fee;

            $change = $input_amount - $output_amount - $fee;

            // if the change is too small, or if we are calculating without any change
            //   then move the change to the fee
            if ($change < self::DUST_AMOUNT OR $with_change_output == false) {
                $fee = $input_amount - $output_amount;
                $change = 0;
            }

            // this transaction matches
            $coin_group = [
                'in_amount'     => $input_amount,
                'change_amount' => $change,
                'fee'           => $fee,
                'fee_per_byte'  => $fee / $size,
                'size'          => $size,
                'txos'          => $txos,
            ];

            return $coin_group;
        }

        // not enough
        return null;
    }

    protected function determinBestMatchingCoinGroup($coin_groups) {
        if (!$coin_groups) {
            return $coin_groups;
        }

        if (count($coin_groups) == 1) {
            return $coin_groups[0];
        }

        // sort by fee (lowest), then fee_per_byte (highest), then smallest change
        usort($coin_groups, function($a, $b) {
            // 1) lowest fee
            if ($a['fee'] != $b['fee']) {
                return $a['fee'] - $b['fee'];
            }

            // 2) HIGHEST fee_per_byte
            if ($b['fee_per_byte'] != $a['fee_per_byte']) {
                return $b['fee_per_byte'] - $a['fee_per_byte'];
            }

            // 3) smallest change
            return $a['change_amount'] - $b['change_amount'];
        });

        return $coin_groups[0];
    }
    
    protected function filterGreenOrConfirmedUTXOs($raw_txos) {
        $filtered_txos = [];
        foreach($raw_txos as $raw_txo) {
            if ($raw_txo['type'] == TXO::CONFIRMED OR $raw_txo['green']) {
                $filtered_txos[] = $raw_txo;
            }
        }
        return $filtered_txos;
    }

    protected function excludePrimes($txos, $prime_input_size) {
        $filtered_txos = [];
        foreach($txos as $txo) {
            if ($txo['amount'] != $prime_input_size) {
                $filtered_txos[] = $txo;
            }
        }
        return $filtered_txos;
    }


    protected function sortTXOs($unsorted_txos, $direction) {
        $sorted_txos = $unsorted_txos;
        usort($sorted_txos, function($a, $b) use ($direction) {
            if ($direction == self::SORT_DESCENDING) {
                return ($b['amount'] - $a['amount']);
                // sort by ID when amounts are the same
                if ($b['amount'] == $a['amount']) {
                    return ($b['id'] - $a['id']);
                }
            }

            // sort by ID when amounts are the same
            if ($b['amount'] == $a['amount']) {
                return ($a['id'] - $b['id']);
            }
            return ($a['amount'] - $b['amount']);
        });

        return $sorted_txos;
    }

    protected function sumOutputs($utxos) {
        $sum = 0;
        foreach($utxos as $utxo) {
            $sum += $utxo['amount'];
        }
        return $sum;
    }

    // this is the minimum size of the transacton
    protected function buildBaseTransactionSize($outputs, $op_return_size) {
        $base_tx_size = self::STATIC_SIZE + self::P2PKH_OUTPUT_SIZE * count($outputs);
        if ($op_return_size > 0) {
            $base_tx_size += self::OPRETURN_PREFIX_SIZE + $op_return_size;
        }
        return $base_tx_size;
    }

    protected function buildTransactionSize($base_tx_size, $input_count, $with_change_output=true) {
        $tx_size = $base_tx_size + (self::P2PKH_INPUT_SIZE * $input_count);
        if ($with_change_output) {
            $tx_size += self::P2PKH_OUTPUT_SIZE;
        }
        return $tx_size;
    }

    // ------------------------------------------------------------------------

}


/*
Prefix and static data:
10

Each signed input (approx):
147

Each P2PKH output (approx):
34

OPRETURN Output:
11 + SIZEOFOPRETURN

*/
