<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use App\Models\TXO;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;

class TXOChooser {

    const SORT_DESCENDING = -1;
    const SORT_ASCENDING  = 1;

    const PREFERRED_VINS_SIZE = 3;

    const STRATEGY_BALANCED = 1;
    const STRATEGY_PRIME    = 2;

    public function __construct(TXORepository $txo_repository) {
        $this->txo_repository = $txo_repository;
    }


    public function chooseUTXOs(PaymentAddress $payment_address, $float_quantity, $float_fee, $strategy=null) {
        if ($strategy === null) { $strategy = self::STRATEGY_BALANCED; }

        switch ($strategy) {
            case self::STRATEGY_PRIME:
                return $this->chooseUTXOsWithPrimeStrategy($payment_address, $float_quantity, $float_fee);
            
            default:
                // STRATEGY_BALANCED
                return $this->chooseUTXOsWithBalancedStrategy($payment_address, $float_quantity, $float_fee);
        }


    }

    // ------------------------------------------------------------------------

    public function chooseUTXOsWithBalancedStrategy(PaymentAddress $payment_address, $float_quantity, $float_fee) {
        // select TXOs (confirmed only first)
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs(iterator_to_array($available_txos), $float_quantity, $float_fee);
        if ($found_utxos) { return $found_utxos; }

        // try confirmed / green unconfirmed
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs($this->filterGreenOrConfirmedUTXOs($available_txos), $float_quantity, $float_fee);
        if ($found_utxos) { return $found_utxos; }

        // try all unconfirmed and confirmed together
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs(iterator_to_array($available_txos), $float_quantity, $float_fee);
        if ($found_utxos) { return $found_utxos; }

        return [];
    }


    // Best strategy for priming (take big UTXOs and break them up into smaller UTXOs)
    public function chooseUTXOsWithPrimeStrategy(PaymentAddress $payment_address, $float_quantity, $float_fee) {
        // select TXOs (confirmed only first)
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true);
        $found_utxos = $this->selectFirstSingleTXO(iterator_to_array($available_txos), $float_quantity, $float_fee);
        if ($found_utxos) { return $found_utxos; }

        // try confirmed / green unconfirmed
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->selectFirstSingleTXO($this->filterGreenOrConfirmedUTXOs($available_txos), $float_quantity, $float_fee);
        if ($found_utxos) { return $found_utxos; }

        // try all unconfirmed and confirmed together
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->selectFirstSingleTXO(iterator_to_array($available_txos), $float_quantity, $float_fee);
        if ($found_utxos) { return $found_utxos; }

        // failed
        return [];
    }

    // ------------------------------------------------------------------------
    
    protected function chooseFromAvailableTXOs($available_txos, $float_quantity, $float_fee) {
        $total_satoshis_needed = CurrencyUtil::valueToSatoshis($float_quantity) + CurrencyUtil::valueToSatoshis($float_fee);

        // look for exact match (no change)
        $matched_txos = $this->findExactMatchUTXOs($available_txos, $total_satoshis_needed);
        if ($matched_txos) { return $matched_txos; }

        // find best grouping
        $txo_groups = $this->groupUTXOs($available_txos, $total_satoshis_needed);
        return $this->balanceTXOGroups($txo_groups, $total_satoshis_needed);
    }

    protected function selectFirstSingleTXO($available_txos, $float_quantity, $float_fee) {
        $total_satoshis_needed = CurrencyUtil::valueToSatoshis($float_quantity) + CurrencyUtil::valueToSatoshis($float_fee);

        $txo_groups = $this->groupUTXOs($available_txos, $total_satoshis_needed);

        if ($txo_groups['large']) { return [$txo_groups['large'][0]]; }
        return [];
    }

    protected function findExactMatchUTXOs($available_txos, $total_satoshis_needed) {
        foreach($available_txos as $available_txo) {
            if ($available_txo['amount'] == $total_satoshis_needed) {
                return [$available_txo];
            }
        }

        return [];
    }

    protected function groupUTXOs($available_txos, $total_satoshis_needed) {
        $sorted = ['large' => [], 'small' => []];

        $sorted_txos = $this->sortTXOs($available_txos, self::SORT_DESCENDING);
        foreach($sorted_txos as $txo) {
            if ($txo['amount'] > $total_satoshis_needed) {
                $sorted['large'][] = $txo;
            } else {
                $sorted['small'][] = $txo;
            }
        }

        // large should be smallest to biggest
        $sorted['large'] = array_reverse($sorted['large']);

        return $sorted;
    }


    protected function balanceTXOGroups($txo_groups, $total_satoshis_needed) {
        // try a grouping of 3 or less small txos
        $small_txos = $this->selectBestTXOsToSatisfyAmount($txo_groups['small'], $total_satoshis_needed);
        if ($small_txos AND count($small_txos) <= self::PREFERRED_VINS_SIZE) {
            return $small_txos;
        }

        // get the first large txo
        if ($txo_groups['large']) {
            return [$txo_groups['large'][0]];
        }

        // fallback to the best set of small txos
        if ($small_txos) { return $small_txos; }

        return null;
    }

    protected function selectBestTXOsToSatisfyAmount($txos, $total_satoshis_needed) {
        $txos_out = [];

        // 
        $allCombinationsFn = function($desired_amount, $start, $end, &$all_groupings, $matched_txos=[], $sum=0) use ($txos, &$allCombinationsFn) {
            for ($i=$start; $i < $end; $i++) { 
                $txo = $txos[$i];
                $txo_amount = $txo['amount'];

                // recurse without this one
                $allCombinationsFn($desired_amount, $i+1, $end, $all_groupings, $matched_txos, $sum);

                $matched_txos[] = $txo;
                $sum += $txo_amount;

                if ($sum >= $desired_amount) {
                    // amount satisfied, stop recursing
                    $all_groupings[] = ['sum' => $sum, 'txos' => $matched_txos, 'count' => count($matched_txos)];
                    return;
                }
            }
        };


        // build all combinations of TXOs
        $selected_txo_groupings = [];
        $allCombinationsFn($total_satoshis_needed, 0, count($txos), $selected_txo_groupings);


        // choose the best grouping (lowest count with the lowest sum)
        $lowest_count = null;
        // get lowest count
        foreach($selected_txo_groupings as $selected_txo_grouping) {
            if ($lowest_count === null) {
                $lowest_count = $selected_txo_grouping['count'];
            } else {
                $lowest_count = min($lowest_count, $selected_txo_grouping['count']);
            }
        }
        $lowest_sum = null;
        $offset_with_lowest_sum = null;
        // get group with the lowest sum that matches the lowest count
        foreach($selected_txo_groupings as $offset => $selected_txo_grouping) {
            if ($selected_txo_grouping['count'] > $lowest_count) { continue; }

            if ($lowest_sum === null OR $selected_txo_grouping['sum'] < $lowest_sum) {
                $lowest_sum = $selected_txo_grouping['sum'];
                $offset_with_lowest_sum = $offset;
            }
        }

        // handle no match that satisfies the needed amount
        if ($offset_with_lowest_sum === null) { return null; }

        // return the best group
        // Log::debug("\$lowest_count=$lowest_count \$lowest_sum=$lowest_sum");
        // Log::debug("All Groups: ".$this->debugDumpGroupings($selected_txo_groupings));
        // Log::debug("Chose: ".$this->debugDumpGroup($selected_txo_groupings[$offset_with_lowest_sum]));
        return $selected_txo_groupings[$offset_with_lowest_sum]['txos'];
    }

    protected function sortTXOs($unsorted_txos, $direction) {
        $sorted_txos = $unsorted_txos;
        usort($sorted_txos, function($a, $b) use ($direction) {
            if ($direction == self::SORT_DESCENDING) {
                return ($b['amount'] - $a['amount']);
            }
            return ($a['amount'] - $b['amount']);
        });

        return $sorted_txos;
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


    // ------------------------------------------------------------------------
    
    protected function debugDumpGroupings($selected_txo_groupings) {
        $out = '';

        $out .= 'total groups: '.count($selected_txo_groupings)."\n";

        foreach($selected_txo_groupings as $selected_txo_grouping) {
            $out .= $this->debugDumpGroup($selected_txo_grouping)."\n";
        }
        return rtrim($out);
    }

    protected function debugDumpGroup($selected_txo_grouping) {
        $line = '';
        $amounts = '';
        foreach($selected_txo_grouping['txos'] as $txo) {
            $amounts = ltrim($amounts.','.$txo['amount'], ',');
        }

        return 'Count: '.$selected_txo_grouping['count'].' | Sum: '.$selected_txo_grouping['sum'].' | Amts: '.$amounts;
    }
}
