<?php

use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class EnhancedBitcoindTransactionBuilderTest extends TestCase {

    protected $useDatabase = true;


    public function testEnhancedBitcoindTransactionBuilder_getrawtransaction() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');

        // try loading from bitcoind
        $tx_data = $builder->buildTransactionData('0000000000000000000000000000000000000000000000000000000000111111');
        // echo "\$tx_data: ".json_encode($tx_data, 192)."\n";

        PHPUnit::assertEquals(0.00022000, $tx_data['valueIn']);
        PHPUnit::assertEquals(0.00012000, $tx_data['valueOut']);
        PHPUnit::assertEquals(0.0001, $tx_data['fees']);

    }

    public function testEnhancedBitcoindTransactionBuilder_cached() {
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        
        $transaction_model = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionStore')->getTransaction('0000000000000000000000000000000000000000000000000000000000aaaaaa');

        $builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');

        // try loading using a cached transaction (aaaaaa will be cached)
        $tx_data = $builder->buildTransactionData('0000000000000000000000000000000000000000000000000000000000111111');

        PHPUnit::assertEquals(0.00022000, $tx_data['valueIn']);
        PHPUnit::assertEquals(0.00012000, $tx_data['valueOut']);
        PHPUnit::assertEquals(0.0001, $tx_data['fees']);
    }

}
