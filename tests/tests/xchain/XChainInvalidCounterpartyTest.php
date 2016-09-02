<?php

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use \PHPUnit_Framework_Assert as PHPUnit;

class XChainInvalidCounterpartyTest extends TestCase {

    protected $useDatabase = true;


    public function testInvalidCounterpartyValue() {
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
        $parsed_tx = $transaction_helper->createSampleTransaction('default_xcp_parsed_01.json')['parsed_tx'];
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
        PHPUnit::assertEquals(0, $tx['counterpartyTx']['quantity']);
        PHPUnit::assertEquals(0, $tx['values']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);
        PHPUnit::assertEquals(0.00008930, $tx['spentAssets']['1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz']['BTC']);
        PHPUnit::assertEquals(0, $tx['spentAssets']['1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz']['LTBCOIN']);
        PHPUnit::assertEquals(0.00003500, $tx['receivedAssets']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']['BTC']);
        PHPUnit::assertEquals(0, $tx['receivedAssets']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']['LTBCOIN']);

        // check that the BTC value is still correct
        PHPUnit::assertEquals(0.00001250, $tx['bitcoinTx']['vout'][0]['value']);
    }

    public function testInvalidateCounterpartyIssuance() {
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

        // mark something invalid
        $parsed_tx['asset'] = 'INVALIDASSET';
        $parsed_tx['counterpartyTx']['asset'] = 'INVALIDASSET';

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
        PHPUnit::assertCount(0, $heard_events);
    }


}
