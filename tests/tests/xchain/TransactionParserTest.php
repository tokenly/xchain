<?php

use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionParserTest extends TestCase {

    protected $useDatabase = true;


    public function testBitcoinTransactionEventBuilder() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');

        $insight_tx_data = json_decode(file_get_contents(base_path().'/tests/fixtures/transactions/sample_xcp_raw_02.json'), true);

        $xstalker_data = [
            'ver' => 1,
            'ts'  => time() * 1000,
            'tx'  => $insight_tx_data,
        ];
        $parsed_data = $builder->buildParsedTransactionData($xstalker_data);

        PHPUnit::assertEquals(1013.54979902, $parsed_data['counterpartyTx']['quantity']);
        PHPUnit::assertEquals(101354979902, $parsed_data['counterpartyTx']['quantitySat']);
        PHPUnit::assertEquals(0.00001250, $parsed_data['counterpartyTx']['dustSize']);
        PHPUnit::assertEquals(1250, $parsed_data['counterpartyTx']['dustSizeSat']);
    }

}
