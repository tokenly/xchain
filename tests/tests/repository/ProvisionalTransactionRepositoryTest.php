<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class ProvisionalTransactionRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddProvisionalTransaction()
    {
        $transaction_helper = app('SampleTransactionsHelper');
        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');

        $transaction = $transaction_helper->createSampleTransaction();
        $provisional_transaction = $provisional_tx_repository->create($transaction);

        // load the provisional transaction
        $loaded_transaction = $provisional_tx_repository->findByID($provisional_transaction['id']);
        PHPUnit::assertEquals($provisional_transaction, $loaded_transaction);

        // load the provisional transaction by txid
        $loaded_transaction = $provisional_tx_repository->findByTXID($provisional_transaction['txid']);
        PHPUnit::assertEquals($provisional_transaction, $loaded_transaction);
    }



    public function testDeleteProvisionalTransaction()
    {
        $transaction_helper = app('SampleTransactionsHelper');
        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');

        $transaction = $transaction_helper->createSampleTransaction();
        $provisional_transaction = $provisional_tx_repository->create($transaction);
        $transaction_2 = $transaction_helper->createSampleTransaction('sample_btc_parsed_02.json');
        $provisional_transaction_2 = $provisional_tx_repository->create($transaction_2);

        // load the provisional transaction
        $loaded_transaction = $provisional_tx_repository->findByID($provisional_transaction['id']);
        PHPUnit::assertEquals($provisional_transaction, $loaded_transaction);

        // delete the provisional transaction
        $count = $provisional_tx_repository->delete($provisional_transaction);
        PHPUnit::assertEquals(1, $count);

        // provisional transaction one is deleted
        $loaded_transaction = $provisional_tx_repository->findByID($provisional_transaction['id']);
        PHPUnit::assertEmpty($loaded_transaction);

        // provisional transaction two is good
        $loaded_transaction = $provisional_tx_repository->findByID($provisional_transaction_2['id']);
        PHPUnit::assertEquals($provisional_transaction_2, $loaded_transaction);

    }

    public function testDeleteProvisionalTransactionByTXID()
    {
        $transaction_helper = app('SampleTransactionsHelper');
        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');

        $transaction = $transaction_helper->createSampleTransaction();
        $provisional_transaction = $provisional_tx_repository->create($transaction);
        $transaction_2 = $transaction_helper->createSampleTransaction('sample_btc_parsed_02.json');
        $provisional_transaction_2 = $provisional_tx_repository->create($transaction_2);

        // load the provisional transaction
        $loaded_transaction = $provisional_tx_repository->findByID($provisional_transaction['id']);
        PHPUnit::assertEquals($provisional_transaction, $loaded_transaction);

        // delete the provisional transaction
        $count = $provisional_tx_repository->deleteByTXID($provisional_transaction_2['txid']);
        PHPUnit::assertEquals(1, $count);

        // provisional transaction two is deleted
        $loaded_transaction_2 = $provisional_tx_repository->findByID($provisional_transaction_2['id']);
        PHPUnit::assertEmpty($loaded_transaction_2);

        // provisional transaction one is good
        $loaded_transaction = $provisional_tx_repository->findByID($provisional_transaction['id']);
        PHPUnit::assertEquals($provisional_transaction, $loaded_transaction);

    }


}
