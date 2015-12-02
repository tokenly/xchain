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

    const DUST_SIZE  = 0.00005430;

    public function __construct(TXORepository $txo_repository) {
        $this->txo_repository = $txo_repository;
    }


    public function chooseUTXOsForPriming(PaymentAddress $payment_address, $float_quantity, $float_fee, $float_minimum_change_size=null, $priming_size=null) {
        return $this->chooseUTXOs($payment_address, $float_quantity, $float_fee, $float_minimum_change_size, self::STRATEGY_PRIME, $priming_size);
    }

    public function chooseUTXOs(PaymentAddress $payment_address, $float_quantity, $float_fee, $float_minimum_change_size=null, $strategy=null, $priming_size=null) {
        if ($float_minimum_change_size === null) { $float_minimum_change_size = self::DUST_SIZE; }
        if ($strategy === null) { $strategy = self::STRATEGY_BALANCED; }

        // Log::debug("begin \$float_quantity=$float_quantity, \$float_fee=$float_fee, \$float_minimum_change_size=$float_minimum_change_size");

        switch ($strategy) {
            case self::STRATEGY_PRIME:
                return $this->chooseUTXOsWithPrimeStrategy($payment_address, $float_quantity, $float_fee, $float_minimum_change_size, $priming_size);
            
            default:
                // STRATEGY_BALANCED
                return $this->chooseUTXOsWithBalancedStrategy($payment_address, $float_quantity, $float_fee, $float_minimum_change_size);
        }


    }

    // ------------------------------------------------------------------------

    public function chooseUTXOsWithBalancedStrategy(PaymentAddress $payment_address, $float_quantity, $float_fee, $float_minimum_change_size) {
        // select TXOs (confirmed only first)
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs(iterator_to_array($available_txos), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // try confirmed / green unconfirmed
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs($this->filterGreenOrConfirmedUTXOs($available_txos), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // try all unconfirmed and confirmed together
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs(iterator_to_array($available_txos), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        return [];
    }


    // Best strategy for priming (take big UTXOs and break them up into smaller UTXOs)
    public function chooseUTXOsWithPrimeStrategy(PaymentAddress $payment_address, $float_quantity, $float_fee, $float_minimum_change_size, $priming_size) {
        // select TXOs (confirmed only first)
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs($this->filterTXOsNotMatchingSize($available_txos, $priming_size), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // try confirmed / green unconfirmed
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs($this->filterTXOsNotMatchingSize($this->filterGreenOrConfirmedUTXOs($available_txos), $priming_size), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // try all unconfirmed and confirmed together
        $available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs($this->filterTXOsNotMatchingSize($available_txos, $priming_size), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // failed
        return [];
    }

    // ------------------------------------------------------------------------
    
    // 1) tries 3 or more small TXOs first
    // 2) tries the first large TXO
    // 3) uses all the small txos found
    protected function chooseFromAvailableTXOs($available_txos, $float_quantity, $float_fee, $float_minimum_change_size) {
        $total_satoshis_needed_without_change = CurrencyUtil::valueToSatoshis($float_quantity) + CurrencyUtil::valueToSatoshis($float_fee);
        $total_satoshis_needed_with_change = $total_satoshis_needed_without_change + CurrencyUtil::valueToSatoshis($float_minimum_change_size);

        // look for exact match (no change)
        $matched_txos = $this->findExactMatchUTXOs($available_txos, $total_satoshis_needed_without_change);
        if ($matched_txos) { return $matched_txos; }

        // find best grouping
        $txo_groups = $this->groupUTXOs($available_txos, $total_satoshis_needed_with_change);
        $matched_txos = $this->selectBestTXOsFromTXOGroups($txo_groups, $total_satoshis_needed_without_change, $total_satoshis_needed_with_change);
        if ($matched_txos) { return $matched_txos; }

        return null;
    }

    protected function selectFirstSingleTXO($available_txos, $float_quantity, $float_fee, $float_minimum_change_size) {
        $total_satoshis_needed = CurrencyUtil::valueToSatoshis($float_quantity) + CurrencyUtil::valueToSatoshis($float_fee) + CurrencyUtil::valueToSatoshis($float_minimum_change_size);

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

    // groups into large (all UTXOs greater than total_satoshis_needed) and small (the rest)
    //   large are sorted by smallest to largest
    //   small are sorted by largest to smallest
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


    // 1) tries 3 or more small TXOs first
    // 2) tries the first large TXO
    // 3) uses all the small txo found
    protected function selectBestTXOsFromTXOGroups($txo_groups, $total_satoshis_needed_without_change, $total_satoshis_needed_with_change) {
        // try a grouping of 3 or less small txos
        $small_txos = $this->selectBestTXOsToSatisfyAmount($txo_groups['small'], $total_satoshis_needed_without_change, $total_satoshis_needed_with_change);
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


    // Chooses the one best TXO set by using the following priorities
    //   1) exact value match (no change)
    //   2) lowest number of UTXOs
    //   3) lowest sum
    protected function selectBestTXOsToSatisfyAmount($txos, $total_satoshis_needed_without_change, $total_satoshis_needed_with_change) {
        // Log::debug("begin selectBestTXOsToSatisfyAmount \$total_satoshis_needed_without_change=".json_encode($total_satoshis_needed_without_change, 192)." \$total_satoshis_needed_with_change=".json_encode($total_satoshis_needed_with_change, 192)."  \$txos=\n".$this->debugDumpTXOs($txos));
        $txos_out = [];

        // build all combinations of TXOs

        // try to all exact matches with no change
        $selected_txo_groupings = [];
        // $allCombinationsFn($total_satoshis_needed_without_change, true, 0, count($txos), $selected_txo_groupings);
        Log::debug("begin __findExactChangeCombinations"); $_t_start = microtime(true);
        $context=[];
        $this->__findExactChangeCombinations($txos, $total_satoshis_needed_without_change, $selected_txo_groupings, $context);
        Log::debug("end __findExactChangeCombinations: ".round((microtime(true) - $_t_start) * 1000)." ms");

        if (!$selected_txo_groupings) {
            // since we couldn't find an exact match with no change, find all matches with change
            $selected_txo_groupings = [];
            // $allCombinationsFn($total_satoshis_needed_with_change, false, 0, count($txos), $selected_txo_groupings);
            $context=[];
            Log::debug("begin __findFewestTXOsCombinations"); $_t_start = microtime(true);
            $this->__findFewestTXOsCombinations($txos, $total_satoshis_needed_with_change, $selected_txo_groupings, $context);
            Log::debug("end __findFewestTXOsCombinations: ".round((microtime(true) - $_t_start) * 1000)." ms");

        }



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

    // returns only green or confirmed TXOs
    protected function filterGreenOrConfirmedUTXOs($raw_txos) {
        $filtered_txos = [];
        foreach($raw_txos as $raw_txo) {
            if ($raw_txo['type'] == TXO::CONFIRMED OR $raw_txo['green']) {
                $filtered_txos[] = $raw_txo;
            }
        }
        return $filtered_txos;
    }

    // returns TXOs that don't match the given size
    protected function filterTXOsNotMatchingSize($raw_txos, $float_amount) {
        if ($float_amount === null) { $float_amount = 0; }
        $amount_satoshis = CurrencyUtil::valueToSatoshis($float_amount);

        $filtered_txos = [];
        foreach($raw_txos as $raw_txo) {
            if ($raw_txo['amount'] == $amount_satoshis) { continue; }
            $filtered_txos[] = $raw_txo;
        }
        return $filtered_txos;
    }

    // ------------------------------------------------------------------------
    
    // finds all combinations of the given txos
    protected function __findExactChangeCombinations($txos, $desired_amount, &$all_groupings, &$context, $matched_txos=[], $sum=0, $start=0, $iteration_count=0) {
        if (!isset($context['iteration_count'])) { $context['iteration_count'] = 0; }
        if (!isset($context['lowest_count_so_far'])) { $context['lowest_count_so_far'] = null; }
        ++$context['iteration_count'];
        if ($context['iteration_count'] > 10000) {
            Log::debug("__findExactChangeCombinations iteration count at {$context['iteration_count']}.  Giving up.");
            return;
        }

        $count = count($txos);

        for ($i=$start; $i < $count; $i++) { 
            $txo = $txos[$i];
            $txo_amount = $txo['amount'];

            $working_sum = $sum + $txo_amount;
            $working_txos = array_merge($matched_txos, [$txo]);
            $working_txos_count = count($working_txos);

            if ($context['lowest_count_so_far'] !== null AND $working_txos_count > $context['lowest_count_so_far']) {
                // this will never be one of the lowest count
                return;
            }

            // does this sum satisfy the requirements
            $is_satisfied = ($working_sum == $desired_amount);

            if ($working_sum >= $desired_amount) {
                // no further matches can help
                $should_recurse = false;
            } else {
                // not found enough yet
                $should_recurse = true;
            }


            if ($is_satisfied) {
                // amount satisfied, stop recursing
                $all_groupings[] = ['sum' => $working_sum, 'txos' => $working_txos, 'count' => $working_txos_count];

                if ($context['lowest_count_so_far'] === null OR $working_txos_count < $context['lowest_count_so_far']) {
                    $context['lowest_count_so_far'] = $working_txos_count;
                }
            }

            // recurse
            if ($should_recurse) {
                $this->__findExactChangeCombinations($txos, $desired_amount, $all_groupings, $context, $working_txos, $working_sum, $i+1, $iteration_count+1);
            }
        }
    }

    // finds all combinations of the given txos
    protected function __findFewestTXOsCombinations($txos, $desired_amount, &$all_groupings, &$context, $matched_txos=[], $sum=0, $start=0, $iteration_count=0) {
        if (!isset($context['lowest_sum_so_far'])) { $context['lowest_sum_so_far'] = null; }
        if (!isset($context['iteration_count'])) { $context['iteration_count'] = 0; }
        ++$context['iteration_count'];
        if ($context['iteration_count'] > 10000) {
            Log::debug("iteration count at {$context['iteration_count']}.  Giving up.");
            return;
        }

        $count = count($txos);

        for ($i=$start; $i < $count; $i++) { 
            $txo = $txos[$i];
            $txo_amount = $txo['amount'];

            $working_sum = $sum + $txo_amount;
            $working_txos = $matched_txos;
            $working_txos[] = $txo;

            // does this sum satisfy the requirements
            $is_big_enough = ($working_sum >= $desired_amount);

            if ($is_big_enough) {
                // only add this utxo as a match if it is lower than the lowest we found so far
                if ($working_sum < $context['lowest_sum_so_far'] OR $context['lowest_sum_so_far'] === null) {
                    $context['lowest_sum_so_far'] = $working_sum;
                    $is_a_match = true;
                    $should_recurse = false;
                } else {
                    // this was big enough, but we have already found a better match
                    $is_a_match = false;
                    $should_recurse = false;
                }
            } else {
                // not big enough yet - keep adding utxos
                $is_a_match = false;
                $should_recurse = true;
            }

            if ($is_a_match) {
                // amount satisfied, stop recursing
                $all_groupings[] = ['sum' => $working_sum, 'txos' => $working_txos, 'count' => count($working_txos)];
            }

            // recurse
            if ($should_recurse) {
                $this->__findFewestTXOsCombinations($txos, $desired_amount, $all_groupings, $context, $working_txos, $working_sum, $i+1, $iteration_count+1);
            }
        }


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
        $amounts = $this->debugDumpTXOAmounts($selected_txo_grouping['txos']);
        return 'Count: '.$selected_txo_grouping['count'].' | Sum: '.$selected_txo_grouping['sum'].' | Amts: '.$amounts;
    }

    protected function debugDumpTXOAmounts($txos) {
        $amounts = '';
        foreach($txos as $txo) {
            $amounts = ltrim($amounts.','.$txo['amount'], ',');
        }

        return $amounts;
    }

    protected function debugDumpTXOs($txos) {
        $out = '';
        foreach($txos as $txo) {
            $out = ltrim($out."\n".$txo['txid'].':'.$txo['n']." (".$txo['amount'].")");
        }

        return $out;
    }
}
