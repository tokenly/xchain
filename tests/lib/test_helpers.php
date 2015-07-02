<?php


function normalize_updated_date($actual_array, $expected_array) {
    if (isset($actual_array['updated_at'])) { $actual_array['updated_at'] = $expected_array['updated_at']; }

    return $actual_array;
}


function format_debug_backtrace($debug_backtrace, $most_recent_limit=null, $start_of_trace_limit=null) {
    // swap end and start
    $end_limit = $start_of_trace_limit;
    $start_limit = $most_recent_limit;

    $out = '';
    $count = count($debug_backtrace);
    $skipping = false;
    $should_limit_end = ($end_limit !== null);
    $should_limit_start = ($start_limit !== null);
    foreach ($debug_backtrace as $offset => $debug_entry) {
        $is_in_end = (!$should_limit_end OR $offset >= ($count - $end_limit));
        $is_in_start = (!$should_limit_start OR $offset < $start_limit);
        if (!$is_in_start OR !$is_in_end) {

            if ($should_limit_start AND $should_limit_end) {
                if (!$is_in_start AND !$is_in_end) {
                    if (!$skipping) {
                        $skipping = true;
                        $out .= "\n...[skipped ".($count - $start_limit - $end_limit)."]...";
                    }

                    continue;
                }
            } else {
                if (!$is_in_start AND $should_limit_start) {
                    continue;
                }   
                if (!$is_in_end AND $should_limit_end) {
                    continue;
                }   
            }
        }   


        $out .= "\n";
        $out .= (isset($debug_entry['file']) ? basename($debug_entry['file']) : '[unknown file]').", ".(isset($debug_entry['line']) ? $debug_entry['line'] : '[unknown line]').": ".$debug_entry['function'];
    }

    return $out."\n";
}
