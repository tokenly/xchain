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
        // echo "\$parsed_transaction: ".json_encode($parsed_transaction, 192)."\n";
        $this->sendTransactionWithConfirmations($parsed_transaction, 0);

        // test balances after
        $ledger_entry_repo = app('App\Repositories\LedgerEntryRepository');
        $balances = $ledger_entry_repo->accountBalancesByAsset($default_account, null);
        // echo "[after] \$balances: ".json_encode($balances, 192)."\n";
        PHPUnit::assertEquals(0.9999, $balances['confirmed']['BTC']);

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
        $total_sent = 0;
        foreach($destinations as $destination_pair) {
            $dest_address = $destination_pair[0];
            $dest_amount = $destination_pair[1];
            $total_sent += $dest_amount;

            if ($dest_address == $source) { continue; }

            if (!isset($values[$dest_address])) { $values[$dest_address] = 0; }
            $values[$dest_address] += $dest_amount;

        }
        
        $parsed_tx['destinations'] = $tx_destinations;
        $parsed_tx['values'] = $values;

        $parsed_tx['fees'] = $send_amount - $total_sent;
        $parsed_tx['bitcoinTx']['fees'] = $send_amount - $total_sent;

        return $parsed_tx;
    }

    protected function sendTransactionWithConfirmations($parsed_tx, $confirmations) {
        if ($confirmations == 0) {
            Event::fire('xchain.tx.received', [$parsed_tx, 0, null, null, ]);
        } else {
            $this->sendConfirmationEvents($confirmations, [$parsed_tx]);
        }
    }
    
    protected function sendConfirmationEvents($confirmations, $parsed_txs) {
        $block_height = 333299 + $confirmations;
        $block = app('SampleBlockHelper')->createSampleBlock('default_parsed_block_01.json', ['hash' => 'BLOCKHASH'.$block_height, 'height' => $block_height, 'parsed_block' => ['height' => $block_height]]);

        $block_event_context = app('App\Handlers\XChain\Network\Bitcoin\Block\BlockEventContextFactory')->newBlockEventContext();

        foreach($parsed_txs as $offset => $parsed_tx) {
            $parsed_tx['confirmations'] = $confirmations;
            Event::fire('xchain.tx.confirmed', [$parsed_tx, $confirmations, 100+$offset, $block, $block_event_context]);
        }
    }

}
