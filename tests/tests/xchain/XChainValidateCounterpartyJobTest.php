<?php

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use \PHPUnit_Framework_Assert as PHPUnit;

class XChainValidateCounterpartyJobTest extends TestCase {

    protected $useDatabase = true;


    public function testValidateCounterpartySendJob() {
        // init mocks
        $mocks = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');

        // listen for events
        $heard_events = [];
        Event::listen('xchain.tx.confirmed', function($tx, $confirmations, $block_seq, $block) use (&$heard_events) {
            $heard_events[] = [$tx, $confirmations, $block_seq, $block];
        });

        // build and fire the job
        $transaction_helper = app('SampleTransactionsHelper');
        $parsed_tx = $transaction_helper->createSampleTransaction(
            'sample_xcp_parsed_01.json',
            [
                'txid' => '1886737b00000000000000000000000000000000000000000000000000000000',
            ]
        )['parsed_tx'];
        $data = [
            'tx'            => $parsed_tx,
            'confirmations' => 1,
            'block_seq'     => 100,
            'block_id'      => $block['id'],
        ];
        $validate_job = app('App\Jobs\XChain\ValidateConfirmedCounterpartydTxJob');
        $mock_job = new SyncJob(app(), $data);
        $validate_job->fire($mock_job, $data);

        // check for the event
        PHPUnit::assertCount(1, $heard_events);

        $tx = $heard_events[0][0];
        
        // check that the asset value is 0
        PHPUnit::assertEquals(533.83451959, $tx['counterpartyTx']['quantity']);
        PHPUnit::assertEquals(53383451959, $tx['counterpartyTx']['quantitySat']);
        PHPUnit::assertEquals(533.83451959, $tx['values']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // check that the BTC value is still correct
        PHPUnit::assertEquals(0.00001250, $tx['bitcoinTx']['vout'][0]['value']);
    }


    // this transaction originally looked like 533 LTBCOIN,
    //   but counterparty confirmed it at 250
    public function testValidateCounterpartyQuantityChangedSendJob() {
        // init mocks
        $mocks = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');

        // listen for events
        $heard_events = [];
        Event::listen('xchain.tx.confirmed', function($tx, $confirmations, $block_seq, $block) use (&$heard_events) {
            $heard_events[] = [$tx, $confirmations, $block_seq, $block];
        });

        // build and fire the job
        $transaction_helper = app('SampleTransactionsHelper');
        $parsed_tx = $transaction_helper->createSampleTransaction(
            'sample_xcp_parsed_01.json',
            [
                'txid' => '1886737b00000000000000000000000000000000000000000000000000010000',
            ]
        )['parsed_tx'];
        $data = [
            'tx'            => $parsed_tx,
            'confirmations' => 1,
            'block_seq'     => 100,
            'block_id'      => $block['id'],
        ];
        $validate_job = app('App\Jobs\XChain\ValidateConfirmedCounterpartydTxJob');
        $mock_job = new SyncJob(app(), $data);
        $validate_job->fire($mock_job, $data);

        // check for the event
        PHPUnit::assertCount(1, $heard_events);

        $tx = $heard_events[0][0];
        
        // check that the quantity was changed to 250
        PHPUnit::assertEquals(25000000000, $tx['counterpartyTx']['quantitySat']);
        PHPUnit::assertEquals(250.0, $tx['counterpartyTx']['quantity']);
        PHPUnit::assertEquals(250.0, $tx['values']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);


        // check that the BTC value is still correct
        PHPUnit::assertEquals(0.00001250, $tx['bitcoinTx']['vout'][0]['value']);
    }


    public function testValidateCounterpartyIssuanceJob() {
        // init mocks
        $mocks = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');

        // listen for events
        $heard_events = [];
        Event::listen('xchain.tx.confirmed', function($tx, $confirmations, $block_seq, $block) use (&$heard_events) {
            $heard_events[] = [$tx, $confirmations, $block_seq, $block];
        });

        // build and fire the job
        $transaction_helper = app('SampleTransactionsHelper');
        $parsed_tx = $transaction_helper->createSampleTransaction(
            'sample_xcp_parsed_issuance_01.json',
            [
                'txid' => 'd9f3f3e500000000000000000000000000000000000000000000000000000000',
            ]
        )['parsed_tx'];
        $data = [
            'tx'            => $parsed_tx,
            'confirmations' => 1,
            'block_seq'     => 100,
            'block_id'      => $block['id'],
        ];
        $validate_job = app('App\Jobs\XChain\ValidateConfirmedCounterpartydTxJob');
        $mock_job = new SyncJob(app(), $data);
        $validate_job->fire($mock_job, $data);

        // check for the event
        PHPUnit::assertCount(1, $heard_events);

        $tx = $heard_events[0][0];

        // check the values
        $counterparty_data = $tx['counterpartyTx'];
        PHPUnit::assertEquals("NEWCOIN", $counterparty_data['asset']);
        PHPUnit::assertEquals("NEWCOIN Description here", $counterparty_data['description']);
        PHPUnit::assertEquals(true, $counterparty_data['divisible']);
    }

    public function testValidateCounterpartyBroadcastJob() {
        // init mocks
        $mocks = app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');

        // listen for events
        $heard_events = [];
        Event::listen('xchain.tx.confirmed', function($tx, $confirmations, $block_seq, $block) use (&$heard_events) {
            $heard_events[] = [$tx, $confirmations, $block_seq, $block];
        });

        // build and fire the job
        $transaction_helper = app('SampleTransactionsHelper');
        $parsed_tx = $transaction_helper->createSampleTransaction(
            'sample_xcp_parsed_broadcast_01.json',
            [
                'txid' => '40c592be00000000000000000000000000000000000000000000000000000000',
            ]
        )['parsed_tx'];
        $data = [
            'tx'            => $parsed_tx,
            'confirmations' => 1,
            'block_seq'     => 100,
            'block_id'      => $block['id'],
        ];
        $validate_job = app('App\Jobs\XChain\ValidateConfirmedCounterpartydTxJob');
        $mock_job = new SyncJob(app(), $data);
        $validate_job->fire($mock_job, $data);

        // check for the event
        PHPUnit::assertCount(1, $heard_events);

        $tx = $heard_events[0][0];

        // check the values
        $counterparty_data = $tx['counterpartyTx'];
        PHPUnit::assertEquals("BLOCKSCAN VERIFY-ADDRESS 7a4exlyjw97esst", $counterparty_data['message']);
        PHPUnit::assertEquals(-1, $counterparty_data['value']);
    }


}
