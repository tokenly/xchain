<?php

return [

	// number of blocks max to backfill in the event of an outage
	'backfill_max' => 450,

    // should log debug timing
    'debugLogTxTiming' => !!env('DEBUG_LOG_TX_TIMING', false),

    // should log debug timing
    'accountsIgnoreBeforeBlockHeight' => env('ACCOUNTS_IGNORE_BEFORE_BLOCK', false),

];
