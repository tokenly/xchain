<?php

use App\Providers\DateProvider\Facade\DateProvider;
use \PHPUnit_Framework_Assert as PHPUnit;

class ComposedTransactionRepositoryTest extends TestCase {

    protected $useRealSQLiteDatabase = true;

    public function testAddComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id      = 'reqid01';
        $transaction_hex = 'testhex01';
        $utxos           = ['longtxid101:0','longtxid102:1'];
        $txid            = 'txid01';
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos, true);

        // load the composed transaction
        $result = $repository->getComposedTransactionByRequestID($request_id);
        PHPUnit::assertEquals($transaction_hex, $result['transaction']);
        PHPUnit::assertEquals($utxos, $result['utxos']);
        PHPUnit::assertEquals($request_id, $result['request_id']);
        PHPUnit::assertEquals($txid, $result['txid']);
        PHPUnit::assertEquals(true, $result['signed']);
    }

    public function testGetEmptyComposedTransactionsByUnknownRequestID()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id = 'unknownreqid01';

        // load the empty composed transaction
        $loaded_transactions = $repository->getComposedTransactionByRequestID($request_id);
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
        $utxos           = ['longtxid101:0','longtxid102:1'];
        $txid            = 'txid01';
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos, true);

        // try storing a duplicate
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex.'foo', $utxos, true);
    }

    public function testStoreOrFetchDuplicateComposedTransaction()
    {
        $now = DateProvider::now();
        DateProvider::setNow($now);
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id      = 'reqid01';
        $transaction_hex = 'testhex01';
        $utxos           = ['longtxid101:0','longtxid102:1'];
        $txid            = 'txid01';
        $stored_result = $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos, true);
        PHPUnit::assertEquals(DateProvider::now()->toDateTimeString(), $stored_result['created_at']->toDateTimeString());

        // storing a duplicate
        $result = $repository->storeOrFetchComposedTransaction($request_id, $txid, $transaction_hex.'foo', $utxos, true);
        PHPUnit::assertEquals($transaction_hex, $result['transaction']);
        PHPUnit::assertEquals(DateProvider::now()->toDateTimeString(), $result['created_at']->toDateTimeString());
    }

    public function testDeleteComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id        = 'reqid01';
        $transaction_hex   = 'testhex01';
        $utxos             = ['longtxid101:0','longtxid102:1'];
        $txid              = 'txid01';
        $request_id_2      = 'reqid02';
        $transaction_hex_2 = 'testhex02';
        $utxos_2           = [201,202];
        $txid_2            = 'txid02';
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos, true);
        $repository->storeComposedTransaction($request_id_2, $txid_2, $transaction_hex_2, $utxos_2, true);

        // load the composed transaction
        $result = $repository->getComposedTransactionByRequestID($request_id);
        PHPUnit::assertEquals($transaction_hex, $result['transaction']);

        // delete the composed transaction
        $count = $repository->deleteComposedTransactionsByRequestID($request_id);
        PHPUnit::assertEquals(1, $count);

        // composed transaction one is deleted
        $result = $repository->getComposedTransactionByRequestID($request_id);
        PHPUnit::assertEmpty($result);

        // composed transaction two is good
        $result = $repository->getComposedTransactionByRequestID($request_id_2);
        PHPUnit::assertEquals($transaction_hex_2, $result['transaction']);



    }


}
