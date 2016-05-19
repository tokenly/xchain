<?php

use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionParserTest extends TestCase {

    protected $useDatabase = true;


    public function testBitcoinTransactionEventBuilder() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('d0010d7ddb1662e381520d29177ea83f81f87428879b57735a894cad8dcae2a2');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);

        PHPUnit::assertNotEmpty($parsed_data['counterpartyTx']);
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

    public function testP2SHBitcoinTransactionEventBuilder() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('99c93bf83cdd4d60f234bd34ee39acc4c1b5eb66db8c932600de12b05c96d0ef');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data: ".json_encode($parsed_data, 192)."\n";

        PHPUnit::assertEmpty($parsed_data['counterpartyTx']);
        PHPUnit::assertEquals(['36wJo1xtHZ2NGcCovy6vHgWvyF7ryMTjHF'], $parsed_data['sources']);
        PHPUnit::assertEquals(['3EMLoeeUZtKBe6pg7wJZdVdXTJWa9pFNMR', '3BwWe6D2znQ7XnMaGaddu1MdyyGWppxRwj'], $parsed_data['destinations']);
        PHPUnit::assertNotEmpty($parsed_data['values']);
        PHPUnit::assertEquals(0.24858765, $parsed_data['values']['3EMLoeeUZtKBe6pg7wJZdVdXTJWa9pFNMR']);
        PHPUnit::assertEquals(0.02898411, $parsed_data['values']['3BwWe6D2znQ7XnMaGaddu1MdyyGWppxRwj']);
        PHPUnit::assertEquals(0.27762848, $parsed_data['bitcoinTx']['valueIn']);
        PHPUnit::assertEquals(0.27757176, $parsed_data['bitcoinTx']['valueOut']);
        PHPUnit::assertEquals(0.00005672, $parsed_data['bitcoinTx']['fees']);

        $expected_fingerprint = hash('sha256',
            '94415dc3ad8ada2f9a65623b67bd525b34f539703760a234beb6c612690abeef:1'
            .'|OP_HASH160 8ae113bf266f4510fabd8e0c258973e3b9f006f2 OP_EQUAL'
            .'|OP_HASH160 706f0d6f929ffc74fc7ae476a9055029cdf8515c OP_EQUAL'
        );
        PHPUnit::assertEquals($expected_fingerprint, $parsed_data['transactionFingerprint']);
    }

    public function testBitcoinParserForPrimeSend() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('2c528f4fe794f90307f3f7863586bf0f644f21e81eccde69112f3eb32faf2fda');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);

        PHPUnit::assertEmpty($parsed_data['counterpartyTx']);
        PHPUnit::assertEquals(21000, $parsed_data['bitcoinTx']['feesSat']);
    }


}
