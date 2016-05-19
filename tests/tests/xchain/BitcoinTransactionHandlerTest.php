<?php

use App\Providers\Accounts\Facade\AccountHandler;
use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class BitcoinTransactionHandlerTest extends TestCase {

    protected $useDatabase = true;


    public function testPrimeSendTransactionHandlerWithNoZeroConfirmation() {
        $address = '1G9FfYagz8DwLaLjso1j2YimcBUh3AcPqw';
        $txid = '314e3c0a8aa23976553d38c39a72fbeb8aab79f2f8a7cfffe63eab5a5bd3e8fb';
        // $txid = '2c528f4fe794f90307f3f7863586bf0f644f21e81eccde69112f3eb32faf2fda';

        // helpers
        $mock_calls = $this->app->make('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this);
        $enhanced_builder = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder');
        $payment_address_helper = app('PaymentAddressHelper');
        $address_helper = app('MonitoredAddressHelper');
        $send_monitor = $address_helper->createSampleMonitoredAddress(null, ['address' => $address, 'monitorType' => 'send']);
        $receive_monitor = $address_helper->createSampleMonitoredAddress(null, ['address' => $address, 'monitorType' => 'receive']);
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json');
        $ledger_entry_repository = app('App\Repositories\LedgerEntryRepository');

        // set up the address
        $payment_address_one = $payment_address_helper->createSamplePaymentAddress(null, ['address' => $address]);
        $account_one = AccountHandler::getAccount($payment_address_one, 'default');

        // get the parsed data
        $bitcoin_data = $enhanced_builder->buildTransactionData($txid);
        $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        $ts = time() * 1000;
        $parsed_tx = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_tx['sources']: ".json_encode($parsed_tx['sources'], 192)."\n";
        // echo "\$parsed_tx['destinations']: ".json_encode($parsed_tx['destinations'], 192)."\n";

        // run it through the bitcoin transaction handler
        $transaction_handler = app('App\Handlers\XChain\Network\Factory\NetworkHandlerFactory')->buildTransactionHandler($parsed_tx['network']);
        $found_addresses = $transaction_handler->findMonitoredAndPaymentAddressesByParsedTransaction($parsed_tx);
        // echo "\$found_addresses: ".json_encode($found_addresses, 192)."\n";

        // update account balances (0 conf)
        // $transaction_handler->updateAccountBalances($found_addresses, $parsed_tx, 0, 100, $block);
        // $balance = $ledger_entry_repository->accountBalancesByAsset($account_one, null);
        // echo "\$balance: ".json_encode($balance, 192)."\n";

        // finalize ledger entries (1 conf) - skipped 0 conf
        $transaction_handler->updateAccountBalances($found_addresses, $parsed_tx, 2, 100, $block);

        // check ledger entries
        $balance = $ledger_entry_repository->accountBalancesByAsset($account_one, null);
        // echo "\$balance: ".json_encode($balance, 192)."\n";

        // make sure balance was deducted
        PHPUnit::assertEquals(0.99833875, $balance['confirmed']['BTC']);
    }


}
