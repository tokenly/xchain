<?php

use App\Models\TXO;
use \PHPUnit_Framework_Assert as PHPUnit;

class TXOTest extends TestCase {

    protected $useDatabase = true;

    public function testAddTXOsFromReceive()
    {
        // receiving a transaction adds TXOs
        $txo_repository = $this->app->make('App\Repositories\TXORepository');

        // setup monitors
        $payment_address_helper = app('PaymentAddressHelper');
        $receiving_address_one = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // receive unconfirmed transactions
        $parsed_txs = $this->receiveUnconfirmedTransactions(1);
        $loaded_txos = $txo_repository->findAll();
        PHPUnit::assertCount(1, $loaded_txos);
        $loaded_txo = $loaded_txos[0];
        PHPUnit::assertEquals('0000000000000000000000000000000000000000000000000000000000000001', $loaded_txo['txid']);
        PHPUnit::assertEquals(0, $loaded_txo['n']);
        PHPUnit::assertEquals(TXO::UNCONFIRMED, $loaded_txo['type']);

        // confirm the transactions (1)
        $this->sendConfirmationEvents(1, $parsed_txs);
        $loaded_txo = $txo_repository->findAll()[0];
        PHPUnit::assertEquals(TXO::UNCONFIRMED, $loaded_txo['type']);

        // confirm the transactions (2)
        $this->sendConfirmationEvents(2, $parsed_txs);
        $loaded_txo = $txo_repository->findAll()[0];
        PHPUnit::assertEquals(TXO::CONFIRMED, $loaded_txo['type']);
    }

    public function testAllocateTXOsWithSend()
    {
        // receiving a transaction adds TXOs
        $txo_repository = $this->app->make('App\Repositories\TXORepository');

        // setup monitors
        $payment_address_helper = app('PaymentAddressHelper');
        $sending_address_one = $payment_address_helper->createSamplePaymentAddress(null, ['address' => '1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1']);

        // create some TXOs
        $sample_txos = [];
        $txid = $this->TXOHelper()->nextTXID();
        $sample_txos[0] = $this->TXOHelper()->createSampleTXO($sending_address_one, ['txid' => $txid,   'n' => 0]);
        $sample_txos[1] = $this->TXOHelper()->createSampleTXO($sending_address_one, ['txid' => $txid,   'n' => 1]);

        // send a transaction (0)
        $sending_tx = $this->buildTransactionWithUTXOs([$sample_txos[0]]);
        $this->sendTransactionWithConfirmations($sending_tx, 0);

        // confirm SENDING
        $loaded_txo = $txo_repository->findByTXIDAndOffset($txid, 0);
        PHPUnit::assertEquals(TXO::SENDING, $loaded_txo['type'], 'Unexpected type of '.TXO::typeIntegerToString($loaded_txo['type']));
        PHPUnit::assertTrue($loaded_txo['spent']);

        // confirm a transaction (1)
        $sending_tx = $this->buildTransactionWithUTXOs([$sample_txos[0]]);
        $this->sendTransactionWithConfirmations($sending_tx, 1);

        // confirm utxo is spent
        $loaded_txo = $txo_repository->findByTXIDAndOffset($txid, 0);
        PHPUnit::assertEquals(TXO::SENT, $loaded_txo['type'], 'Unexpected type of '.TXO::typeIntegerToString($loaded_txo['type']));
        PHPUnit::assertTrue($loaded_txo['spent']);

    }


    public function testSendTXOsWithChangeCreatesUTXOs()
    {
        // receiving a transaction adds TXOs
        $txo_repository = $this->app->make('App\Repositories\TXORepository');

        // setup monitors
        $payment_address_helper = app('PaymentAddressHelper');
        $sending_address_one = $payment_address_helper->createSamplePaymentAddress(null, ['address' => '1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1']);

        // create some TXOs
        $sample_txos = [];
        $txid = $this->TXOHelper()->nextTXID();
        $sample_txos[0] = $this->TXOHelper()->createSampleTXO($sending_address_one, ['txid' => $txid,   'n' => 0]);
        $sample_txos[1] = $this->TXOHelper()->createSampleTXO($sending_address_one, ['txid' => $txid,   'n' => 1]);

        // send a transaction (0)
        $sending_tx = $this->buildTransactionWithUTXOs([$sample_txos[0]]);
        $this->sendTransactionWithConfirmations($sending_tx, 0);

        // confirm SENDING
        $loaded_txo = $txo_repository->findByTXIDAndOffset($txid, 0);
        PHPUnit::assertEquals(TXO::SENDING, $loaded_txo['type'], 'Unexpected type of '.TXO::typeIntegerToString($loaded_txo['type']));
        PHPUnit::assertTrue($loaded_txo['spent']);

        // find the change UTXO
        $loaded_change_txo = $txo_repository->findByTXIDAndOffset($sending_tx['txid'], 1);
        // echo "\$loaded_change_txo: ".json_encode($loaded_change_txo, 192)."\n";
        PHPUnit::assertNotEmpty($loaded_change_txo);


    }


    public function testSendingFromOnePaymentAddressToAnother()
    {
        // receiving a transaction adds TXOs
        $txo_repository = $this->app->make('App\Repositories\TXORepository');

        // setup monitors
        $payment_address_helper = app('PaymentAddressHelper');
        $sending_address_one = $payment_address_helper->createSamplePaymentAddress(null, ['address' => '1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1']);
        $receiving_address_one = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // create some TXOs
        $sample_txos = [];
        $txid = $this->TXOHelper()->nextTXID();
        $sample_txos[0] = $this->TXOHelper()->createSampleTXO($sending_address_one, ['txid' => $txid,   'n' => 0]);
        $sample_txos[1] = $this->TXOHelper()->createSampleTXO($sending_address_one, ['txid' => $txid,   'n' => 1]);

        // send from sender to receiver
        $sending_tx = $this->buildTransactionWithUTXOs([$sample_txos[0]]);
        $this->sendTransactionWithConfirmations($sending_tx, 0);

        // confirm sending
        $loaded_txo = $txo_repository->findByTXIDAndOffset($txid, 0);
        PHPUnit::assertEquals(TXO::SENDING, $loaded_txo['type'], 'Unexpected type of '.TXO::typeIntegerToString($loaded_txo['type']));
        PHPUnit::assertTrue($loaded_txo['spent']);

        // receive unconfirmed transactions
        $loaded_txo = $txo_repository->findByTXIDAndOffset($sending_tx['txid'], 0);
        PHPUnit::assertEquals(0, $loaded_txo['n']);
        PHPUnit::assertEquals(TXO::UNCONFIRMED, $loaded_txo['type']);
    }

    // ------------------------------------------------------------------------
    
    protected function TXOHelper() {
        if (!isset($this->txo_helper)) { $this->txo_helper = $this->app->make('SampleTXOHelper'); }
        return $this->txo_helper;
    }


    protected function receiveUnconfirmedTransactions($count=5, $filename='sample_btc_parsed_01.json') {
        $parsed_txs = [];
        for ($i=0; $i < $count; $i++) { 
            $parsed_tx = $this->buildSampleTransactionVars($i, $filename);
            $parsed_txs[] = $parsed_tx;

            Event::fire('xchain.tx.received', [$parsed_tx, 0, null, null, ]);
        }

        return $parsed_txs;
    }

    protected function buildSampleTransactionVars($i, $filename) {
        $tx_helper = app('SampleTransactionsHelper');

        $parsed_tx = $tx_helper->loadSampleTransaction($filename, ['txid' => str_repeat('0', 63).($i+1)]);
        foreach ($parsed_tx['bitcoinTx']['vin'] as $offset => $vin) {
            $parsed_tx['bitcoinTx']['vin'][$offset]['txid'] = str_repeat('a', 62).($i).($offset+1);
            $parsed_tx['bitcoinTx']['vin'][$offset]['vout'] = 0;
        }

        return $parsed_tx;
    }

    protected function sendConfirmationEvents($confirmations, $parsed_txs) {
        $block_height = 333299 + $confirmations;
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH'.$block_height, 'height' => $block_height, 'parsed_block' => ['height' => $block_height]]);

        $block_event_context = app('App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContextFactory')->newBlockEventContext();

        foreach($parsed_txs as $offset => $parsed_tx) {
            $parsed_tx['confirmations'] = $confirmations;
            Event::fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, 100+$offset, $block, $block_event_context]);
        }
    }



    protected function buildTransactionWithUTXOs($txos_to_send, $sample_txid_offset=100, $filename='sample_btc_parsed_01.json') {
        $tx_helper = app('SampleTransactionsHelper');

        $parsed_tx = $tx_helper->loadSampleTransaction($filename, ['txid' => str_repeat('4', 60).sprintf('%04d', $sample_txid_offset)]);
        $prototype_vin = $parsed_tx['bitcoinTx']['vin'][0];
        
        $vins = [];
        foreach($txos_to_send as $txo_to_send) {
            $vin = $prototype_vin;
            $vin['txid'] = $txo_to_send['txid'];
            $vin['vout'] = $txo_to_send['n'];
            $vins[] = $vin;
        }

        $parsed_tx['bitcoinTx']['vin'] = $vins;

        return $parsed_tx;
    }

    protected function sendTransactionWithConfirmations($parsed_tx, $confirmations) {
        if ($confirmations == 0) {
            Event::fire('xchain.tx.received', [$parsed_tx, 0, null, null, ]);
        } else {
            $this->sendConfirmationEvents($confirmations, [$parsed_tx]);
        }
    }

}
