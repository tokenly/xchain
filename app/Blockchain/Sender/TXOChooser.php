<?php

namespace App\Blockchain\Sender;

use App\Models\PaymentAddress;
use App\Models\TXO;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Support\Facades\Log;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class TXOChooser {

    const SORT_DESCENDING = -1;
    const SORT_ASCENDING  = 1;

    const PREFERRED_VINS_SIZE = 3;

    const STRATEGY_BALANCED = 1;
    const STRATEGY_PRIME    = 2;

    const DUST_SIZE  = 0.00005430;

    const MAX_INPUTS_PER_TRANSACTION = 145;

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
    
    public function chooseSpecificUTXOs(PaymentAddress $payment_address, $utxo_list)
    {
		$available_txos = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true);
		if(!$available_txos){
			return [];
		}
		$list = array();
		foreach($available_txos as $txo){
			foreach($utxo_list as $custom_txo){
				if(isset($custom_txo['txid']) AND isset($custom_txo['n'])){
					if($txo->txid == $custom_txo['txid'] AND $txo->n == $custom_txo['n']){
						$list[] = $txo;
					}
				}
			}
		}
		return $list;
	}

    // ------------------------------------------------------------------------

    public function chooseUTXOsWithBalancedStrategy(PaymentAddress $payment_address, $float_quantity, $float_fee, $float_minimum_change_size) {
        // select TXOs (confirmed only first)
        $confirmed_only_available_txos = iterator_to_array($this->txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true));
        $found_utxos = $this->chooseFromAvailableTXOs($confirmed_only_available_txos, $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // try confirmed / green unconfirmed
        $confirmed_or_unconfirmed_txos_collection = $this->txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true);
        $found_utxos = $this->chooseFromAvailableTXOs($this->filterGreenOrConfirmedUTXOs($confirmed_or_unconfirmed_txos_collection), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // try all unconfirmed and confirmed together
        $found_utxos = $this->chooseFromAvailableTXOs(iterator_to_array($confirmed_or_unconfirmed_txos_collection), $float_quantity, $float_fee, $float_minimum_change_size);
        if ($found_utxos) { return $found_utxos; }

        // if no matches, then try without a minimum change size
        if ($float_minimum_change_size > 0) {
            return $this->chooseUTXOsWithBalancedStrategy($payment_address, $float_quantity, $float_fee, 0);
        }

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
        // Log::debug("begin __findExactChangeCombinations"); $_t_start = microtime(true);
        $selected_txo_groupings = $this->__findExactChangeCombinations($txos, $total_satoshis_needed_without_change);
        // Log::debug("end __findExactChangeCombinations: ".round((microtime(true) - $_t_start) * 1000)." ms");

        if (!$selected_txo_groupings) {
            // since we couldn't find an exact match with no change, find all matches with change
            $selected_txo_groupings = [];
            // $allCombinationsFn($total_satoshis_needed_with_change, false, 0, count($txos), $selected_txo_groupings);
            // Log::debug("begin __findFewestTXOsCombinations"); $_t_start = microtime(true);
            $selected_txo_groupings = $this->__findFewestTXOsCombinations($txos, $total_satoshis_needed_with_change);
            // Log::debug("end __findFewestTXOsCombinations: ".round((microtime(true) - $_t_start) * 1000)." ms");

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
            // sort by ID when amounts are the same
            if ($b['amount'] == $a['amount']) {
                return ($a['id'] - $b['id']);
            }

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

    protected function __findExactChangeCombinations($txos, $target_amount) {
        $context = [
            'isSatisfiedFn' => function($target_amount, $sum) {
                return ($sum == $target_amount);
            },

            'shouldContinueFn' => function($target_amount, $sum, $working_chain, $txos, $context) {
                if ($context['lowest_count_far'] !== null AND count($working_chain) >= $context['lowest_count_far']) { return false; }
                return true;
            },
        ];
        $combinations = $this->__changeCombinations($target_amount, $this->sortTXOs($txos, self::SORT_DESCENDING), $context);
        // Log::debug("__findExactChangeCombinations iteration_count=".$context['iteration_count']." cominations: ".$this->debugDumpGroupings($combinations));
        // $this->__last_context = $context;
        return $combinations;
    }

    protected function __findFewestTXOsCombinations($txos, $target_amount) {
        $context = [
            'isSatisfiedFn' => function($target_amount, $sum, $working_chain) {
                return ($sum >= $target_amount);
            },

            //   1) lowest number of UTXOs
            //   2) lowest sum
            'shouldContinueFn' => function($target_amount, $sum, $working_chain, $txos, $context) {
                if ($context['lowest_count_far'] !== null AND count($working_chain) >= $context['lowest_count_far']) { return false; }
                if ($context['lowest_sum_so_far'] !== null AND $sum >= $context['lowest_sum_so_far']) { return false; }
                return true;
            },
        ];
        $combinations = $this->__changeCombinations($target_amount, $this->sortTXOs($txos, self::SORT_DESCENDING), $context);
        if ($context['gave_up']) {
            // fall back to all UTXOs
            Log::debug("falling back to naive UTXO selection algorithm");
            $combinations = [$this->naiveUTXOsAsCombination($txos, $target_amount, self::MAX_INPUTS_PER_TRANSACTION)];
        }
        // Log::debug("__findFewestTXOsCombinations iteration_count=".$context['iteration_count']." combinations: ".$this->debugDumpGroupings($combinations));
        // $this->__last_context = $context;
        return $combinations;
    }

    protected function __changeCombinations($target_amount, $txos, &$context, $chain=[], $starting_offset=0) {
        if (!isset($context['iteration_count'])) { $context['iteration_count'] = 0; }
        if (!isset($context['lowest_sum_so_far'])) { $context['lowest_sum_so_far'] = null; }
        if (!isset($context['lowest_count_far'])) { $context['lowest_count_far'] = null; }
        if (!isset($context['gave_up'])) { $context['gave_up'] = false; }

        $combinations = [];

        $txos_count = count($txos);
        for ($working_offset=$starting_offset; $working_offset < $txos_count; $working_offset++) { 
            if ($context['iteration_count'] >= 25000) {
                if (!$context['gave_up']) {
                    $msg = "__changeCombinations iteration count at {$context['iteration_count']}.  Giving up.";
                    EventLog::warning('txoChooser.highIterationCount', $msg);
                    $context['gave_up'] = true;
                }
                return $combinations;
            }

            ++$context['iteration_count'];

            $working_chain = array_merge($chain, [$working_offset]);

            $sum = 0;
            foreach($working_chain as $offset) {
                $sum += $txos[$offset]['amount'];
            }

            // is satisfied
            if ($context['isSatisfiedFn']($target_amount, $sum, $working_chain, $txos, $context)) {
                $working_chain_count = count($working_chain);

                // build working txos for this chain entry
                $working_txos = [];
                foreach($working_chain as $offset) {
                    $working_txos[] = $txos[$offset];
                }
                $combinations[] = ['sum' => $sum, 'txos' => $working_txos, 'count' => $working_chain_count];
                $context['lowest_sum_so_far'] = $context['lowest_sum_so_far'] === null ? $sum : min($context['lowest_sum_so_far'], $sum);
                $context['lowest_count_far'] = $context['lowest_count_far'] === null ? $working_chain_count : min($context['lowest_count_far'], $working_chain_count);
            }

            // should continue
            $should_continue = ($sum < $target_amount);
            if ($should_continue AND isset($context['shouldContinueFn'])) {
                $should_continue = $context['shouldContinueFn']($target_amount, $sum, $working_chain, $txos, $context);
            }

            if ($should_continue AND $working_offset + 1 < $txos_count) {
                // continue to recurse
                $child_combinations = $this->__changeCombinations($target_amount, $txos, $context, $working_chain, $working_offset + 1);
                if ($child_combinations) {
                    $combinations = array_merge($combinations, $child_combinations);
                }
            }
        }

        return $combinations;
    }

    // 
    protected function naiveUTXOsAsCombination($txos, $target_amount, $maximum_number_of_utxos_allowed) {
        // try ascending first
        $sorted_txos = $this->sortTXOs($txos, self::SORT_ASCENDING);
        $utxos_combination = $this->selectNaiveTXOs($sorted_txos, $target_amount, $maximum_number_of_utxos_allowed);

        if ($utxos_combination === null) {
            // ascending didn't work - try descending
            $sorted_txos = $this->sortTXOs($txos, self::SORT_DESCENDING);
            $utxos_combination = $this->selectNaiveTXOs($sorted_txos, $target_amount, $maximum_number_of_utxos_allowed);
        }

        if ($utxos_combination === null) {
            throw new Exception("Unable to find any naive UTXO combination to satisfy amount ".json_encode($target_amount, 192), 1);
        }

        return $utxos_combination;
    }

    protected function selectNaiveTXOs($sorted_txos, $target_amount, $maximum_number_of_utxos_allowed) {
        $sum = 0;
        $txos_count = 0;
        $matched_txos = [];
        foreach($sorted_txos as $txo) {
            $sum += $txo['amount'];
            $matched_txos[] = $txo;

            if ($sum >= $target_amount) { break; }
            ++$txos_count;
            if ($txos_count >= $maximum_number_of_utxos_allowed) { break; }
        }

        if ($sum < $target_amount) { return null; }

        return ['sum' => $sum, 'txos' => $matched_txos, 'count' => $txos_count];
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
        $total = 0;
        foreach($txos as $txo) {
            $out = ltrim($out."\n".$txo['txid'].':'.$txo['n']." (".$txo['amount'].")");
            $total += $txo['amount'];
        }

        return $out."\nTOTAL=".CurrencyUtil::satoshisToValue($total);
    }
}
