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

    public function createSampleTransaction($filename='sample_xcp_parsed_01.json', $parsed_tx_overrides=[]) {
        $parsed_tx = $this->loadSampleTransaction($filename);

        $parsed_tx = array_replace_recursive($parsed_tx, $parsed_tx_overrides);

        $block_confirmed_hash = isset($parsed_tx['bitcoinTx']['blockhash']) ? $parsed_tx['bitcoinTx']['blockhash'] : null;
        $is_mempool           = isset($parsed_tx['bitcoinTx']['blockhash']) ? 0 : 1;
        $block_seq            = null;

        $transaction_model = $this->transaction_repository->create($parsed_tx, $block_confirmed_hash, $is_mempool, $block_seq);
        return $transaction_model;
    }

}