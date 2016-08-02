<?php

use App\Providers\Accounts\Facade\AccountHandler;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class AccountUpdaterTest extends TestCase {

    protected $useDatabase = true;

    public function testPrimingTransactionUpdatesBalancesCorrectly()
    {
        $user = $this->app->make('\UserHelper')->createSampleUser();
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user);
        $my_address = $payment_address['address'];
        $default_account = AccountHandler::getAccount($payment_address);

        // test balances before
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1, $balances['confirmed']['BTC']);

        // generate a multi priming transaction
        $parsed_transaction = $this->buildTransaction(
            0.4,
            $my_address,
            [
                [ $my_address, 0.0001, ],
                [ $my_address, 0.0001, ],
                [ $my_address, 0.0001, ],
                [ $my_address, 0.3996, ],
            ]
        );
        $this->sendTransactionWithConfirmations($parsed_transaction, 0);

        // test balances after
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        // echo "[after] \$balances: ".json_encode($balances, 192)."\n";
        PHPUnit::assertEquals(0.9999, $balances['confirmed']['BTC']);

    }

    public function testIssuanceTransactionUpdatesAccount() {
        $user = $this->app->make('\UserHelper')->createSampleUser();

        $address = '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j';
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user, ['address' => $address, 'private_key_token' => '',]);
        $my_address = $payment_address['address'];
        $default_account = AccountHandler::getAccount($payment_address);

        // test balances before
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1, $balances['confirmed']['BTC']);


        // $tx_helper = app('SampleTransactionsHelper');
        // $bitcoin_data = $tx_helper->loadSampleTransaction('issuance01-raw.json', []);
        // $builder = app('App\Handlers\XChain\Network\Bitcoin\BitcoinTransactionEventBuilder');
        // $ts = time() * 1000;
        // $parsed_data = $builder->buildParsedTransactionData($bitcoin_data, $ts);
        // echo "\$parsed_data: ".json_encode($parsed_data, 192)."\n";

        // build and send the issuance transaction
        $tx_helper = app('SampleTransactionsHelper');
        $sample_txid_offset = 101;
        $parsed_transaction = $tx_helper->loadSampleTransaction('issuance01.json', ['txid' => str_repeat('5', 60).sprintf('%04d', $sample_txid_offset)]);
        $this->sendTransactionWithConfirmations($parsed_transaction, 0);

        // test balances after
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1409527.13, $balances['unconfirmed']['LTBCOIN']);

        // confirm it
        $this->sendTransactionWithConfirmations($parsed_transaction, 2, true);


        // test balances after
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1409527.13, $balances['confirmed']['LTBCOIN']);
    }

    public function testIssuanceTransactionForIndivisibleAsset() {
        $user = $this->app->make('\UserHelper')->createSampleUser();

        $address = '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j';
        $payment_address = $this->app->make('\PaymentAddressHelper')->createSamplePaymentAddress($user, ['address' => $address, 'private_key_token' => '',]);
        $my_address = $payment_address['address'];
        $default_account = AccountHandler::getAccount($payment_address);

        // test balances before
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1, $balances['confirmed']['BTC']);

        // build and send the issuance transaction
        $tx_helper = app('SampleTransactionsHelper');
        $sample_txid_offset = 101;
        $parsed_transaction = $tx_helper->loadSampleTransaction('issuance02.json', ['txid' => str_repeat('5', 60).sprintf('%04d', $sample_txid_offset)]);
        $this->sendTransactionWithConfirmations($parsed_transaction, 0);

        // test balances after
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1000, $balances['unconfirmed']['TESTASSET']);

        // confirm it
        $this->sendTransactionWithConfirmations($parsed_transaction, 2, true);


        // test balances after
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        PHPUnit::assertEquals(1000, $balances['confirmed']['TESTASSET']);
    }


    // ------------------------------------------------------------------------

    protected function buildTransaction($send_amount, $source, $destinations, $sample_txid_offset=100, $filename='sample_btc_parsed_01.json') {
        $tx_helper = app('SampleTransactionsHelper');

        $parsed_tx = $tx_helper->loadSampleTransaction($filename, ['txid' => str_repeat('4', 60).sprintf('%04d', $sample_txid_offset)]);

        // fix vin
        $parsed_tx['bitcoinTx']['vin'][0]['addr']     = $source;
        $parsed_tx['bitcoinTx']['vin'][0]['value']    = $send_amount;
        $parsed_tx['bitcoinTx']['vin'][0]['valueSat'] = CurrencyUtil::valueToSatoshis($send_amount);
        
        $parsed_tx['sources'] = [$destinations[0][0]];


        $tx_destinations = [];
        $values = [];
        $received_assets = [];
        $total_sent = 0;
        $total_sent_to_self = 0;
        foreach($destinations as $destination_pair) {
            $dest_address = $destination_pair[0];
            $dest_amount = $destination_pair[1];
            $total_sent += $dest_amount;

            if ($dest_address == $source) {
                $total_sent_to_self += $dest_amount;
                continue;
            }

            if (!isset($values[$dest_address])) { $values[$dest_address] = 0; }
            $values[$dest_address] += $dest_amount;

            if (!isset($received_assets[$dest_address])) { $received_assets[$dest_address]['BTC'] = 0; }
            $received_assets[$dest_address]['BTC'] += $dest_amount;

        }
        
        $parsed_tx['destinations'] = $tx_destinations;
        $parsed_tx['values'] = $values;

        $parsed_tx['fees'] = $send_amount - $total_sent;
        $parsed_tx['bitcoinTx']['fees'] = $send_amount - $total_sent;

        // fix spentAssets and receivedAssets
        $parsed_tx['spentAssets'] = [ $source => ['BTC' => $send_amount - $total_sent_to_self] ];
        $parsed_tx['receivedAssets'] = $received_assets;

        return $parsed_tx;
    }

    protected function sendTransactionWithConfirmations($parsed_tx, $confirmations, $confirm_counterparty_tx=null) {
        if ($confirmations == 0) {
            Event::fire('xchain.tx.received', [$parsed_tx, 0, null, null, ]);
        } else {
            $this->sendConfirmationEvents($confirmations, [$parsed_tx], $confirm_counterparty_tx);
        }
    }
    
    protected function sendConfirmationEvents($confirmations, $parsed_txs, $confirm_counterparty_tx=null) {
        $block_height = 333299 + $confirmations;
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH'.$block_height, 'height' => $block_height, 'parsed_block' => ['height' => $block_height]]);

        $block_event_context = app('App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContextFactory')->newBlockEventContext();

        foreach($parsed_txs as $offset => $parsed_tx) {
            $parsed_tx['confirmations'] = $confirmations;

            if ($confirm_counterparty_tx === true) {
                $parsed_tx['counterpartyTx']['validated'] = true;
            }

            Event::fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, 100+$offset, $block, $block_event_context]);
        }
    }

}
