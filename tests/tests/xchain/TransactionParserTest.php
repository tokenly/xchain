<?php

use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
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

    public function testBitcoinParserForMultipleBTCSend() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('2a27ce159ba873db71b2ab7694694ad7507d60bf6576ddac838f39b46882d588');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data: ".json_encode($parsed_data, 192)."\n";

        PHPUnit::assertArrayHasKey('spentAssets', $parsed_data);
        PHPUnit::assertArrayHasKey('receivedAssets', $parsed_data);
        PHPUnit::assertEquals(0.00010750, $parsed_data['spentAssets']['1KtN9EW3jRJuHyk4iPiA2f6FXdcc8KpoVW']['BTC']);
        PHPUnit::assertEquals(9.72160678, $parsed_data['receivedAssets']['1FAv42GaDuQixSzEzSbx6aP1Kf4WVWpQUY']['BTC']);
    }

    public function testBitcoinParserChangeForMultipleBTCSend() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('43c0d41bde15f636eecdfd09d051ba75659ec560ff28bf6c5024d0bacf2a812f');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data: ".json_encode($parsed_data, 192)."\n";

        PHPUnit::assertEquals(10.50009955, $parsed_data['spentAssets']['1DpEo4NTrqiCGk5C6xT3TWaXwD4Z1peiBi']['BTC']);
        PHPUnit::assertEquals(10.5, $parsed_data['receivedAssets']['1Nij3ZbfgHX5QyD3J9neK8RK1L6ziKsvHD']['BTC']);
        PHPUnit::assertArrayHasKey('receivedAssets', $parsed_data);
    }

    public function testParserForCounterpartySend() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('1932c7a9901068da1377fcf8860b2a13eaa086fb008e3d797d43274748787039');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data: ".json_encode($parsed_data, 192)."\n";

        PHPUnit::assertArrayHasKey('spentAssets', $parsed_data);
        PHPUnit::assertEquals(0.0001543, $parsed_data['spentAssets']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']['BTC']);
        PHPUnit::assertEquals(1, $parsed_data['spentAssets']['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']['TOKENLY']);

        PHPUnit::assertEquals(0.00005430, $parsed_data['receivedAssets']['1YxC7GN6NipW12XLPuCFcTFfkMKYAu1Lb']['BTC']);
        PHPUnit::assertEquals(1, $parsed_data['receivedAssets']['1YxC7GN6NipW12XLPuCFcTFfkMKYAu1Lb']['TOKENLY']);

    }

    public function testParserForCounterpartyIssuance() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('19ad5baa46b1a39a8391447f22724e8ab1486e201ef8e7eb3363f1e5ec6c8e41');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data: ".json_encode($parsed_data, 192)."\n";

        PHPUnit::assertArrayHasKey('spentAssets', $parsed_data);
        PHPUnit::assertEquals(0.00008374, $parsed_data['spentAssets']['1KiswqEUc9PjyGxgu7d7ypqgikNErkzfkb']['BTC']);
        PHPUnit::assertEquals(0.5, $parsed_data['spentAssets']['1KiswqEUc9PjyGxgu7d7ypqgikNErkzfkb']['XCP']);

        PHPUnit::assertArrayNotHasKey('BTC', $parsed_data['receivedAssets']['1KiswqEUc9PjyGxgu7d7ypqgikNErkzfkb']);
        PHPUnit::assertEquals(100, $parsed_data['receivedAssets']['1KiswqEUc9PjyGxgu7d7ypqgikNErkzfkb']['DUELPOINTS']);
    }

    public function testParserForCounterpartyOrder() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('f783cac74813dc5d1fcbc597849dfc243e36d7153ed67e3f473a5e8e2dcdf1ad');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data['spentAssets']: ".json_encode($parsed_data['spentAssets'], 192)."\n";

        PHPUnit::assertArrayHasKey('spentAssets', $parsed_data);
        PHPUnit::assertArrayHasKey('1ADHMo6KGgJZRxrPUYUUVGFYeZ2noAfDRQ', $parsed_data['spentAssets']);
        PHPUnit::assertEquals(0.00020000, $parsed_data['spentAssets']['1ADHMo6KGgJZRxrPUYUUVGFYeZ2noAfDRQ']['BTC']);
    }

    public function testParserForCounterpartySendIndivisible() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);

        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress(null, ['address' => '1MYNxBbNN44Zo6tfSZFKjeWC462ferRvqa', 'private_key_token' => '',]);
        $default_account = AccountHandler::getAccount($payment_address);

        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $bitcoin_data = $enhanced_builder->buildTransactionData('5075c83b50131667c2e51842b3196286194ee475163c2aabbcc7877d1ff9af1d');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_tx = $builder->buildParsedTransactionData($bitcoin_data, $ts);

        PHPUnit::assertArrayHasKey('spentAssets', $parsed_tx);
        PHPUnit::assertEquals(0.00005470, $parsed_tx['receivedAssets']['1MYNxBbNN44Zo6tfSZFKjeWC462ferRvqa']['BTC']);
        PHPUnit::assertEquals(200, $parsed_tx['receivedAssets']['1MYNxBbNN44Zo6tfSZFKjeWC462ferRvqa']['KRAKENCARD']);

        // apply the transaction
        $parsed_tx['counterpartyTx']['validated'] = true;
        $confirmations = 3;
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');
        $block_event_context = app('App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContextFactory')->newBlockEventContext();
        Event::fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, 101, $block, $block_event_context]);

        // check the ledger
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger->accountBalancesByAsset($default_account, LedgerEntry::CONFIRMED);
        // echo "\$balances: ".json_encode($balances, 192)."\n";
        PHPUnit::assertEquals(200, $balances['KRAKENCARD']);

    }

}
