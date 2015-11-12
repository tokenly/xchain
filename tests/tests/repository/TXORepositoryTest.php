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

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $sample_txo   = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0, 'green' => true]);
        $sample_txo_2 = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 1, 'type' => TXO::UNCONFIRMED]);
        $sample_txo_3 = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid_2, 'n' => 0, 'spent' => 1]);

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

        // get all txos by payment_address (filtered by type)
        $reloaded_txos = $txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED]);
        PHPUnit::assertCount(1, $reloaded_txos);
        PHPUnit::assertEquals($sample_txo_2->toArray(), $reloaded_txos[0]->toArray());

        // get all txos by payment_address (filtered by unspent)
        $reloaded_txos = $txo_repository->findByPaymentAddress($payment_address, null, true);
        PHPUnit::assertCount(2, $reloaded_txos);
        PHPUnit::assertEquals($sample_txo->toArray(), $reloaded_txos[0]->toArray());
        PHPUnit::assertEquals($sample_txo_2->toArray(), $reloaded_txos[1]->toArray());

        // get all txos by payment_address (filtered by green)
        $reloaded_txos = $txo_repository->findByPaymentAddress($payment_address, null, null, true);
        PHPUnit::assertCount(1, $reloaded_txos);
        PHPUnit::assertEquals($sample_txo->toArray(), $reloaded_txos[0]->toArray());

        // get all txos by payment_address (filtered by all)
        $reloaded_txos = $txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true, true);
        PHPUnit::assertCount(1, $reloaded_txos);
        PHPUnit::assertEquals($sample_txo->toArray(), $reloaded_txos[0]->toArray());
    }

    public function testUpdateOrCreateTXOs()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $default_account = AccountHandler::getAccount($payment_address);

        // create...
        $attributes = [
            'txid'   => $txid,
            'n'      => 0,
            'type'   => TXO::CONFIRMED,
            'script' => 'testscript',
            'spent'  => false,
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

    public function testUpdateByTXOIdentifiers()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();
        $txid_2 = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $default_account = AccountHandler::getAccount($payment_address);

        // create...
        $attributes = [
            'txid'   => $txid,
            'n'      => 0,
            'type'   => TXO::CONFIRMED,
            'script' => 'testscript',
            'spent'  => false,
        ];
        $txo_model_1 = $txo_repository->updateOrCreate($attributes, $payment_address, $default_account);
        $txo_model_2 = $txo_repository->updateOrCreate((['n' => 1] + $attributes), $payment_address, $default_account);
        $txo_model_3 = $txo_repository->updateOrCreate((['txid' => $txid_2] + $attributes), $payment_address, $default_account);
        $txo_model_4 = $txo_repository->updateOrCreate((['txid' => $txid_2, 'n' => 1] + $attributes), $payment_address, $default_account);


        // update
        $update_vars = ['script' => 'testscriptupdated'];
        $txo_repository->updateByTXOIdentifiers(["$txid:1","$txid_2:1",], $update_vars);

        $reloaded_txo_1 = $txo_repository->findByID($txo_model_1['id']);
        $reloaded_txo_2 = $txo_repository->findByID($txo_model_2['id']);
        $reloaded_txo_3 = $txo_repository->findByID($txo_model_3['id']);
        $reloaded_txo_4 = $txo_repository->findByID($txo_model_4['id']);
        PHPUnit::assertEquals('testscript', $reloaded_txo_1['script']);
        PHPUnit::assertEquals('testscriptupdated', $reloaded_txo_2['script']);
        PHPUnit::assertEquals('testscript', $reloaded_txo_3['script']);
        PHPUnit::assertEquals('testscriptupdated', $reloaded_txo_4['script']);

        // update (2)
        $update_vars = ['script' => 'testscriptupdated222'];
        $txo_repository->updateByTXOIdentifiers(["$txid:0",], $update_vars);

        $reloaded_txo_1 = $txo_repository->findByID($txo_model_1['id']);
        $reloaded_txo_2 = $txo_repository->findByID($txo_model_2['id']);
        $reloaded_txo_3 = $txo_repository->findByID($txo_model_3['id']);
        $reloaded_txo_4 = $txo_repository->findByID($txo_model_4['id']);
        PHPUnit::assertEquals('testscriptupdated222', $reloaded_txo_1['script']);
        PHPUnit::assertEquals('testscriptupdated', $reloaded_txo_2['script']);
        PHPUnit::assertEquals('testscript', $reloaded_txo_3['script']);
        PHPUnit::assertEquals('testscriptupdated', $reloaded_txo_4['script']);
    }


    public function testDeleteTXO()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $sample_txo   = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0]);

        // load the txo by id
        $reloaded_txo = $txo_repository->findByID($sample_txo['id']);
        PHPUnit::assertEquals($sample_txo->toArray(), $reloaded_txo->toArray());

        // delete
        $txo_repository->delete($sample_txo);
        $reloaded_txo = $txo_repository->findByID($sample_txo['id']);
        PHPUnit::assertEmpty($reloaded_txo);
    }

    public function testDeleteByTXID()
    {
        // add one
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $sample_txo   = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 0]);
        $sample_txo_2 = $this->TXOHelper()->createSampleTXO($payment_address, ['txid' => $txid,   'n' => 1]);

        // verify existence
        PHPUnit::assertEquals($sample_txo->toArray(), $txo_repository->findByID($sample_txo['id'])->toArray());
        PHPUnit::assertEquals($sample_txo_2->toArray(), $txo_repository->findByID($sample_txo_2['id'])->toArray());

        // delete and verify deletion
        $txo_repository->deleteByTXID($txid);
        PHPUnit::assertEmpty($txo_repository->findByID($sample_txo['id']));
        PHPUnit::assertEmpty($txo_repository->findByID($sample_txo_2['id']));
    }


    /**
     * @expectedException        Illuminate\Database\QueryException
     * @expectedExceptionMessage UNIQUE constraint failed
     */
    public function testDuplicateTXO()
    {
        $txo_repository = $this->app->make('App\Repositories\TXORepository');
        $txid = $this->TXOHelper()->nextTXID();
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();

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
