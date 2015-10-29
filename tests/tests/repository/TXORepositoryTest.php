<?php

use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use \PHPUnit_Framework_Assert as PHPUnit;

class TXORepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testAddAndFindTXOs()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();
        $txid_2 = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $sample_txo   = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0]);
        $sample_txo_2 = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 1]);
        $sample_txo_3 = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid_2, 'n' => 0]);

        // load the txo by id
        $reloaded_txo = $txo_repository->findByID($sample_txo['id']);
        PHPUnit::assertEquals($sample_txo->toArray(), $reloaded_txo->toArray());

        // get all txos by txid
        $reloaded_txos = $txo_repository->findByTXID($txid);
        PHPUnit::assertCount(2, $reloaded_txos);

        // get txos by txid
        PHPUnit::assertEquals($sample_txo->toArray(), $txo_repository->findByTXIDAndOffset($txid, 0)->toArray());
        PHPUnit::assertEquals($sample_txo_2->toArray(), $txo_repository->findByTXIDAndOffset($txid, 1)->toArray());
        PHPUnit::assertEquals($sample_txo_3->toArray(), $txo_repository->findByTXIDAndOffset($txid_2, 0)->toArray());

        // get all txos by payment_address
        $reloaded_txos = $txo_repository->findByPaymentAddress($payment_address);
        PHPUnit::assertCount(3, $reloaded_txos);

    }

    public function testUpdateOrCreateTXOs()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $default_account = AccountHandler::getAccount($payment_address);

        // create...
        $attributes = [
            'txid'  => $txid,
            'n'     => 0,
            'type'  => TXO::CONFIRMED,
            'spent' => false,
        ];
        $txo_model = $txo_repository->updateOrCreate($attributes, $payment_address, $default_account);


        // load the txo by id
        $reloaded_txo = $txo_repository->findByID($txo_model['id']);
        PHPUnit::assertEquals($attributes['txid'], $reloaded_txo['txid']);
        PHPUnit::assertEquals($attributes['n'], $reloaded_txo['n']);
        PHPUnit::assertEquals($attributes['type'], $reloaded_txo['type']);

        // update
        $update_vars = $attributes;
        $update_vars['type'] = TXO::SENDING;
        $updated_txo_model = $txo_repository->updateOrCreate($update_vars, $payment_address, $default_account);
        PHPUnit::assertEquals($txo_model['id'], $updated_txo_model['id']);

        $reloaded_txo = $txo_repository->findByID($txo_model['id']);
        PHPUnit::assertEquals(TXO::SENDING, $reloaded_txo['type']);
    }


    public function testDeleteTXO()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $sample_txo   = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0]);

        // load the txo by id
        $reloaded_txo = $txo_repository->findByID($sample_txo['id']);
        PHPUnit::assertEquals($sample_txo->toArray(), $reloaded_txo->toArray());

        // delete
        $txo_repository->delete($sample_txo);
        $reloaded_txo = $txo_repository->findByID($sample_txo['id']);
        PHPUnit::assertEmpty($reloaded_txo);
    }


    /**
     * @expectedException        Illuminate\Database\QueryException
     * @expectedExceptionMessage UNIQUE constraint failed
     */
    public function testDuplicateTXO()
    {
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress();

        // add one
        $sample_txo = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0]);

        // try to add again
        $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0]);
    }




    protected function TXOHelper() {
        if (!isset($this->txo_helper)) { $this->txo_helper = $this->app->make('SampleTXOHelper'); }
        return $this->txo_helper;
    }

}
