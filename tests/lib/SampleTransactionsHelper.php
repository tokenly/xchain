<?php

use App\Repositories\TransactionRepository;

/**
*  SampleTransactionsHelper
*/
class SampleTransactionsHelper
{

    public function __construct(TransactionRepository $transaction_repository) {
        $this->transaction_repository = $transaction_repository;
    }

    public function loadSampleTransaction($filename) {
        $data = json_decode(file_get_contents(base_path().'/tests/fixtures/transactions/'.$filename), true);
        if ($data === null) { throw new Exception("file not found: $filename", 1); }
        return $data;
    }

    public function createSampleTransaction($filename='sample_xcp_parsed_01.json', $block_seen=300000, $parsed_tx_overrides=[]) {
        $parsed_tx = $this->loadSampleTransaction($filename);

        $parsed_tx = array_merge_recursive($parsed_tx, $parsed_tx_overrides);

        $transaction_model = $this->transaction_repository->create($parsed_tx, $block_seen);
        return $transaction_model;
    }

}