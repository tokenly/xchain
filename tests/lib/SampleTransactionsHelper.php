<?php

use App\Repositories\TransactionRepository;
use Tokenly\CurrencyLib\CurrencyUtil;

/**
*  SampleTransactionsHelper
*/
class SampleTransactionsHelper
{

    public function __construct(TransactionRepository $transaction_repository) {
        $this->transaction_repository = $transaction_repository;
    }

    public function loadSampleTransaction($filename, $parsed_tx_overrides=[]) {
        $data = json_decode(file_get_contents(base_path().'/tests/fixtures/transactions/'.$filename), true);
        if ($data === null) { throw new Exception("file not found: $filename", 1); }

        $data = $this->applyOverrides($data, $parsed_tx_overrides);

        return $data;
    }

    public function createSampleTransaction($filename='sample_xcp_parsed_01.json', $parsed_tx_overrides=[]) {
        $parsed_tx = $this->loadSampleTransaction($filename, $parsed_tx_overrides);

        $block_confirmed_hash = isset($parsed_tx['bitcoinTx']['blockhash']) ? $parsed_tx['bitcoinTx']['blockhash'] : null;
        $is_mempool           = isset($parsed_tx['bitcoinTx']['blockhash']) ? 0 : 1;
        $block_seq            = null;

        $transaction_model = $this->transaction_repository->create($parsed_tx, $block_confirmed_hash, $is_mempool, $block_seq);
        return $transaction_model;
    }

    public function createSampleCounterpartySendTransaction($source=null, $dest=null, $asset=null, $quantity_float=null, $parsed_tx_overrides=[]) {

        if ($source         === null) { $source = '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j'; }
        if ($dest           === null) { $dest = '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU'; }
        if ($asset          === null) { $asset = 'TOKENLY'; }
        if ($quantity_float === null) { $quantity_float = 100; }

        $parsed_tx_json = file_get_contents(base_path().'/tests/fixtures/transactions/default_xcp_placeholder_01.json.template');
        $parsed_tx_json = str_replace('___SOURCE___', $source, $parsed_tx_json);
        $parsed_tx_json = str_replace('___DEST___', $dest, $parsed_tx_json);
        $parsed_tx_json = str_replace('___ASSET___', $asset, $parsed_tx_json);
        $parsed_tx_json = str_replace('___QUANTITY___', $quantity_float, $parsed_tx_json);
        $parsed_tx = json_decode($parsed_tx_json, true);
        $parsed_tx = $this->applyOverrides($parsed_tx, $parsed_tx_overrides);


        $block_confirmed_hash = isset($parsed_tx['bitcoinTx']['blockhash']) ? $parsed_tx['bitcoinTx']['blockhash'] : null;
        $is_mempool           = isset($parsed_tx['bitcoinTx']['blockhash']) ? 0 : 1;
        $block_seq            = null;

        $transaction_model = $this->transaction_repository->create($parsed_tx, $block_confirmed_hash, $is_mempool, $block_seq);
        return $transaction_model;
    }

    // ------------------------------------------------------------------------
    
    protected function applyOverrides($data, $parsed_tx_overrides) {
        if (isset($parsed_tx_overrides['txid'])) {
            if (!isset($parsed_tx_overrides['bitcoinTx']) OR !isset($parsed_tx_overrides['bitcoinTx']['txid'])) {
                $parsed_tx_overrides['bitcoinTx']['txid'] = $parsed_tx_overrides['txid'];
            }
        }

        $data = array_replace_recursive($data, $parsed_tx_overrides);

        return $data;

    }
}