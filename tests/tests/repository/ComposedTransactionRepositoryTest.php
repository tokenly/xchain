<?php

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
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos);

        // load the composed transaction
        $result = $repository->getComposedTransactionByRequestID($request_id);
        PHPUnit::assertEquals($transaction_hex, $result['transaction']);
        PHPUnit::assertEquals($utxos, $result['utxos']);
        PHPUnit::assertEquals($request_id, $result['request_id']);
        PHPUnit::assertEquals($txid, $result['txid']);
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
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos);

        // try storing a duplicate
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex.'foo', $utxos);
    }

    public function testStoreOrFetchDuplicateComposedTransaction()
    {
        $repository = app('App\Repositories\ComposedTransactionRepository');

        $request_id      = 'reqid01';
        $transaction_hex = 'testhex01';
        $utxos           = ['longtxid101:0','longtxid102:1'];
        $txid            = 'txid01';
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos);

        // storing a duplicate
        $result = $repository->storeOrFetchComposedTransaction($request_id, $txid, $transaction_hex.'foo', $utxos);
        PHPUnit::assertEquals($transaction_hex, $result['transaction']);
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
        $repository->storeComposedTransaction($request_id, $txid, $transaction_hex, $utxos);
        $repository->storeComposedTransaction($request_id_2, $txid_2, $transaction_hex_2, $utxos_2);

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
