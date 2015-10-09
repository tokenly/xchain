<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class ComposedTransactionRepositoryTest extends TestCase {

    protected $useRealSQLiteDatabase = true;

    public function testAddComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id      = 'reqid01';
        $transaction_hex = 'testhex01';
        $txid            = 'mytxid01';
        $repository->storeComposedTransactions($request_id, [$transaction_hex], $txid);

        // load the composed transaction
        $loaded_transactions = $repository->getComposedTransactionsByRequestID($request_id);
        PHPUnit::assertEquals([$transaction_hex], $loaded_transactions);
    }

    public function testGetEmptyComposedTransactionsByUnknownRequestID()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id = 'unknownreqid01';

        // load the empty composed transaction
        $loaded_transactions = $repository->getComposedTransactionsByRequestID($request_id);
        PHPUnit::assertNull($loaded_transactions);
    }


    /**
     * @expectedException        Illuminate\Database\QueryException
     * @expectedExceptionMessage UNIQUE constraint failed
     */
    public function testAddDuplicateComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id      = 'reqid01';
        $transaction_hex = 'testhex01';
        $txid            = 'mytxid01';
        $repository->storeComposedTransactions($request_id, [$transaction_hex], $txid);

        // try storing a duplicate
        $repository->storeComposedTransactions($request_id, [$transaction_hex.'foo'], $txid);
    }

    public function testStoreOrFetchDuplicateComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id      = 'reqid01';
        $transaction_hex = 'testhex01';
        $txid            = 'mytxid01';
        $repository->storeComposedTransactions($request_id, [$transaction_hex], $txid);

        // storing a duplicate
        $fetched_transactions = $repository->storeOrFetchComposedTransactions($request_id, [$transaction_hex.'foo'], $txid);
        PHPUnit::assertEquals([$transaction_hex], $fetched_transactions);
    }

    public function testDeleteComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id        = 'reqid01';
        $transaction_hex   = 'testhex01';
        $txid              = 'mytxid01';
        $request_id_2      = 'reqid02';
        $transaction_hex_2 = 'testhex02';
        $txid_2            = 'mytxid02';
        $repository->storeComposedTransactions($request_id, [$transaction_hex], $txid);
        $repository->storeComposedTransactions($request_id_2, [$transaction_hex_2], $txid_2);

        // load the composed transaction
        $loaded_transactions = $repository->getComposedTransactionsByRequestID($request_id);
        PHPUnit::assertEquals([$transaction_hex], $loaded_transactions);

        // delete the composed transaction
        $count = $repository->deleteComposedTransactionsByRequestID($request_id);
        PHPUnit::assertEquals(1, $count);

        // composed transaction one is deleted
        $loaded_transactions = $repository->getComposedTransactionsByRequestID($request_id);
        PHPUnit::assertEmpty($loaded_transactions);

        // composed transaction two is good
        $loaded_transactions = $repository->getComposedTransactionsByRequestID($request_id_2);
        PHPUnit::assertEquals([$transaction_hex_2], $loaded_transactions);



    }


}
