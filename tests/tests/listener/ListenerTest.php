<?php

use Mockery as m;
use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class ListenerTest extends TestCase
{

    protected $useDatabase = true;

    public function testBitcoinEvent() {
        $transactions = $this->buildTransactionsHelper();

        // listen for fired event
        $heard_tx_data = null;
        $this->app->make('events')->listen('xchain.tx.received', function($tx_data) use (&$heard_tx_data) {
            $heard_tx_data = $tx_data;
        });

        $data = $transactions->formatTxAsXstalkerJobData($transactions->getSampleBitcoinTransaction());
        $this->buildBTCTransactionJob()->fire($this->getQueueJob(), $data);

        // validate tx data
        PHPUnit::assertNotNull($heard_tx_data);
        PHPUnit::assertEquals(['1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1'], $heard_tx_data['sources']);
        PHPUnit::assertEquals(['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD'], $heard_tx_data['destinations']);
        // 1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1 was change and is ignored
        // '1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1' => 0.00361213, 
        PHPUnit::assertEquals(['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD' => 0.004], $heard_tx_data['values']);
        // PHPUnit::assertEquals(761213, $heard_tx_data['quantitySat']);
        PHPUnit::assertEquals('cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741', $heard_tx_data['bitcoinTx']['txid']);
    }


    public function testXCPEvent() {
        $transactions = $this->buildTransactionsHelper();

        // listen for fired event
        $heard_tx_data = null;
        $this->app->make('events')->listen('xchain.tx.received', function($tx_data) use (&$heard_tx_data) {
            $heard_tx_data = $tx_data;
        });

        $data = $transactions->formatTxAsXstalkerJobData($transactions->getSampleCounterpartyTransaction());
        $this->buildBTCTransactionJob()->fire($this->getQueueJob(), $data);

        // validate tx data
        PHPUnit::assertNotNull($heard_tx_data);
        PHPUnit::assertEquals(['1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz'], $heard_tx_data['sources']);
        PHPUnit::assertEquals(['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD'], $heard_tx_data['destinations']);
        // PHPUnit::assertEquals(533.83451959, $heard_tx_data['quantity']);
        // PHPUnit::assertEquals(53383451959, $heard_tx_data['quantitySat']);
        PHPUnit::assertEquals(['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD' => 533.83451959], $heard_tx_data['values']);

        PHPUnit::assertEquals('LTBCOIN', $heard_tx_data['counterpartyTx']['asset']);
    }



    public function testIndividisbleXCPEvent() {
        $transactions = $this->buildTransactionsHelper();

        // listen for fired event
        $heard_tx_data = null;
        $this->app->make('events')->listen('xchain.tx.received', function($tx_data) use (&$heard_tx_data) {
            $heard_tx_data = $tx_data;
        });

        $data = $transactions->formatTxAsXstalkerJobData($transactions->getSampleEarlyCounterpartyTransaction());
        $this->buildBTCTransactionJob()->fire($this->getQueueJob(), $data);

        // validate tx data
        PHPUnit::assertNotNull($heard_tx_data);
        PHPUnit::assertEquals(['15ra6w1RmFrL7q3VeJniQ55W91QGjehbRW'], $heard_tx_data['sources']);
        PHPUnit::assertEquals(['1MCEtBB5X4ercRsvq2GmgysZ9ZDsqj8Xh7'], $heard_tx_data['destinations']);
        // PHPUnit::assertEquals(1, $heard_tx_data['quantity']);
        PHPUnit::assertEquals(['1MCEtBB5X4ercRsvq2GmgysZ9ZDsqj8Xh7' => 1], $heard_tx_data['values']);
    }


    public function setUp()
    {
        parent::setUp();

        $this->mockXCPDClient($this->app);
        $this->mockAssetCache($this->app);
    }



    protected function getQueueJob()
    {
        return new Illuminate\Queue\Jobs\BeanstalkdJob(
            m::mock('Illuminate\Container\Container'),
            m::mock('Pheanstalk\Pheanstalk')->shouldReceive('delete')->getMock(),
            m::mock('Pheanstalk\Job'),
            'default'
        );
    }

    protected function buildTransactionsHelper() {
        return $this->app->make('App\Listener\Test\Transactions');
    }

    protected function buildBTCTransactionJob() {
        return $this->app->make('App\Listener\Job\BTCTransactionJob');
    }


    protected function mockXCPDClient($app)
    {
        $app->bind('Tokenly\XCPDClient\Client', function() {
            $transactions = $this->app->make('App\Listener\Test\Transactions');
            $client = m::mock('Tokenly\XCPDClient\Client');
            $client->shouldReceive('get_asset_info')->with(['assets' => ['LTBCOIN']])->andReturn([$transactions->sampleLTBCoinAssetInfo()]);
            $client->shouldReceive('get_asset_info')->with(['assets' => ['EARLY']])->andReturn([$transactions->sampleEarlyAssetInfo()]);
            return $client;
        });
    }

    protected function mockAssetCache($app)
    {
        $app->bind('Tokenly\CounterpartyAssetInfoCache\Cache', function() {
            $cache = m::mock('Tokenly\CounterpartyAssetInfoCache\Cache');
            $cache->shouldReceive('isDivisible')->with('LTBCOIN')->andReturn(true);
            $cache->shouldReceive('isDivisible')->with('EARLY')->andReturn(false);
            return $cache;
        });
    }

}
