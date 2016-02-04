<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddXCPTransaction()
    {
        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json');

        // load from repo
        $tx_repo = app('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($transaction_model['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($transaction_model['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(0, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals('00000000000000000347e702fdc4d6ed74dca01844857deb5fec560c25b14d51', $loaded_transaction_model['block_confirmed_hash']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }

    public function testAddBTCTransaction()
    {
        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json');
        $parsed_tx = $transaction_model['parsed_tx'];

        // load from repo
        $tx_repo = app('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($parsed_tx['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($parsed_tx['txid'], $loaded_transaction_model['txid']);
        PHPUnit::assertEquals(0, $loaded_transaction_model['is_mempool']);
        PHPUnit::assertEquals('00000000000000003a1e5abc2d7af7f38a614d2fcbafe309b7e8aa147d508a9c', $loaded_transaction_model['block_confirmed_hash']);
        PHPUnit::assertGreaterThan(0, $loaded_transaction_model['id']);
    }

    public function testFindByTXID()
    {
        // insert
        $created_transaction_model = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json');

        // load from repo
        $tx_repo = app('App\Repositories\TransactionRepository');
        $loaded_transaction_model = $tx_repo->findByTXID($created_transaction_model['txid']);
        PHPUnit::assertNotEmpty($loaded_transaction_model);
        PHPUnit::assertEquals($created_transaction_model['id'], $loaded_transaction_model['id']);
        PHPUnit::assertEquals($created_transaction_model['address'], $loaded_transaction_model['address']);
    }

    public function testFindAllTransactionsConfirmedInBlockHashes()
    {
        // insert
        $created_transaction_models = [];
        $created_transaction_models[] = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => 'TX01', 'bitcoinTx' => ['blockhash' => 'BLOCKHASH01']]);
        $created_transaction_models[] = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => 'TX02', 'bitcoinTx' => ['blockhash' => 'BLOCKHASH02']]);
        $created_transaction_models[] = $this->txHelper()->createSampleTransaction('sample_xcp_parsed_01.json', ['txid' => 'TX03', 'bitcoinTx' => ['blockhash' => 'BLOCKHASH03']]);

        // load from repo
        $tx_repo = app('App\Repositories\TransactionRepository');
        $loaded_transaction_models = $tx_repo->findAllTransactionsConfirmedInBlockHashes(['BLOCKHASH02','BLOCKHASH03']);
        PHPUnit::assertNotEmpty($loaded_transaction_models);
        PHPUnit::assertCount(2, $loaded_transaction_models);
        PHPUnit::assertEquals('TX02', $loaded_transaction_models[0]['txid']);
        PHPUnit::assertEquals('TX03', $loaded_transaction_models[1]['txid']);
    }

    public function testDeleteByTXID()
    {
        // insert
        $created_transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json');
        $tx_repo = app('App\Repositories\TransactionRepository');

        // delete
        PHPUnit::assertTrue($tx_repo->deleteByTXID($created_transaction_model['txid']));

        // load from repo
        $loaded_transaction_model = $tx_repo->findByTXID($created_transaction_model['txid']);
        PHPUnit::assertEmpty($loaded_transaction_model);
    }

    public function testAddressLookupTableInsert()
    {
        $tx_repo = app('App\Repositories\TransactionRepository');

        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_02.json');
        $parsed_tx = $transaction_model['parsed_tx'];

        // load entries from lookup table
        $lookup_entries = DB::table('transaction_address_lookup')->where('transaction_id', $transaction_model['id'])->get();
        $lookup_entries = json_decode(json_encode($lookup_entries), true);
        PHPUnit::assertCount(4, $lookup_entries);
        PHPUnit::assertEquals(array(
            array(
                'transaction_id' => '1',
                'address' => 'RECIPIENT01',
                'direction' => '2',
            ),
            array(
                'transaction_id' => '1',
                'address' => 'RECIPIENT02',
                'direction' => '2',
            ),
            array(
                'transaction_id' => '1',
                'address' => 'SENDER01',
                'direction' => '1',
            ),
            array(
                'transaction_id' => '1',
                'address' => 'SENDER02',
                'direction' => '1',
            ),
            ), $lookup_entries
        );

        // deleting the transaction also deletes the lookup entries
        PHPUnit::assertTrue($tx_repo->deleteByTXID($transaction_model['txid']));
        $lookup_entries = DB::table('transaction_address_lookup')->where('transaction_id', $transaction_model['id'])->get();
        PHPUnit::assertCount(0, $lookup_entries);

    }

    public function testAddressLookupTableDeleteAll() {
        $tx_repo = app('App\Repositories\TransactionRepository');

        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_02.json');

        // delete all
        $tx_repo->deleteAll();

        // deleting the transaction also deletes the lookup entries
        $lookup_entries = DB::table('transaction_address_lookup')->where('transaction_id', $transaction_model['id'])->get();
        PHPUnit::assertCount(0, $lookup_entries);
    }

    public function testAddressLookupTableDeleteOlderThan() {
        $tx_repo = app('App\Repositories\TransactionRepository');

        // insert
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_02.json');

        // delete older than
        $tx_repo->deleteOlderThan(Carbon::now()->addDay(1));

        // deleting the transaction also deletes the lookup entries
        $lookup_entries = DB::table('transaction_address_lookup')->where('transaction_id', $transaction_model['id'])->get();
        PHPUnit::assertCount(0, $lookup_entries);
    }

    public function testAddressLookupTableSelectQuery()
    {
        $tx_repo = app('App\Repositories\TransactionRepository');

        // insert
        $sample_block_hash_1 = '00000000000000000000000000000000000000000000000000000000aaaaaaaa';
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_01.json', ['bitcoinTx' => ['blockhash' => $sample_block_hash_1]]);
        $parsed_tx = $transaction_model['parsed_tx'];

        $sample_block_hash_2 = '00000000000000000000000000000000000000000000000000000000bbbbbbbb';
        $transaction_model = $this->txHelper()->createSampleTransaction('sample_btc_parsed_02.json', ['bitcoinTx' => ['blockhash' => $sample_block_hash_1]]);
        $parsed_tx = $transaction_model['parsed_tx'];

        $transactions = $tx_repo->findAllTransactionsConfirmedInBlockHashesInvolvingAddresses([$sample_block_hash_1, $sample_block_hash_2], ['RECIPIENT01']);
        PHPUnit::assertCount(1, $transactions);
        PHPUnit::assertEquals('7390428cd965d4e179b485811429688973176f664e538fa705e50f8e8806390e', $transactions[0]['txid']);

        $transactions = $tx_repo->findAllTransactionsConfirmedInBlockHashesInvolvingAddresses([$sample_block_hash_1, $sample_block_hash_2], ['RECIPIENT01','RECIPIENT02']);
        PHPUnit::assertCount(1, $transactions);
        PHPUnit::assertEquals('7390428cd965d4e179b485811429688973176f664e538fa705e50f8e8806390e', $transactions[0]['txid']);

        $transactions = $tx_repo->findAllTransactionsConfirmedInBlockHashesInvolvingAddresses([$sample_block_hash_1, $sample_block_hash_2], ['RECIPIENT01','RECIPIENT02','1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1']);
        PHPUnit::assertCount(2, $transactions);
        PHPUnit::assertEquals('cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741', $transactions[0]['txid']);
        PHPUnit::assertEquals('7390428cd965d4e179b485811429688973176f664e538fa705e50f8e8806390e', $transactions[1]['txid']);

        $transactions = $tx_repo->findAllTransactionsConfirmedInBlockHashesInvolvingAddresses([$sample_block_hash_1, $sample_block_hash_2], ['NOTFOUND']);
        PHPUnit::assertCount(0, $transactions);
    }

    // ------------------------------------------------------------------------

    protected function txHelper() {
        if (!isset($this->sample_tx_helper)) { $this->sample_tx_helper = app('SampleTransactionsHelper'); }
        return $this->sample_tx_helper;
    }

}
