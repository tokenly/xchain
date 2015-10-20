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

        $expected_fingerprint = hash('sha256',
            'b61bff55d9c5b14f32515b2b00eaf72d3f1c1b951f0747b3199ade27544efd74:2'
            .'|OP_DUP OP_HASH160 c56cb39f9b289c0ec4ef6943fa107c904820fe09 OP_EQUALVERIFY OP_CHECKSIG'
            .'|1 022cacedc1a455d6665433a2d852951b52014def0fb4769a3323a7ab0cccc1adc3 03e74c5611fa7923392e1390838caf81814003baba2fab77e2c8a4bd9834ae6fa1 020ba30b3409037b6653fdcc916775fe7f2a2dbca9b934cd51e5d207f56a8178e7 3 OP_CHECKMULTISIG'
            .'|OP_DUP OP_HASH160 76d12dfbe58981a0008ab832f6f02ebfd2f78661 OP_EQUALVERIFY OP_CHECKSIG'
        );
        PHPUnit::assertEquals($expected_fingerprint, $parsed_data['transactionFingerprint']);
    }

}
