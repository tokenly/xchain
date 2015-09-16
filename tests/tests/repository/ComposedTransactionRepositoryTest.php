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


}
