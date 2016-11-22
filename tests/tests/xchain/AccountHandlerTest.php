<?php

use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Util\ArrayToTextTable;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class AccountHandlerTest extends TestCase {

    protected $useDatabase = true;

    public function testMoveConfirmedBalancesWithSend() {
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $account = AccountHandler::getAccount($payment_address);
        $address = $payment_address['address'];

        // put some confirmed FOOCOIN in the account
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['FOOCOIN' => 20, 'BTC' => 1], $payment_address);

        // put some unconfirmed FOOCOIN in the account
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['FOOCOIN' => 80], $payment_address, $with_utxos=true, 'default', $txid='SAMPLETX002', LedgerEntry::CONFIRMED);

        // send the entirety of the FOOCOIN
        $confirmations = 0;
        $source = $address;
        $quantity = 100;
        $asset = 'FOOCOIN';
        $transaction_model = app('SampleTransactionsHelper')->createSampleCounterpartySendTransaction($source, $dest=null, $asset, $quantity);
        $parsed_tx = $transaction_model['parsed_tx'];
        AccountHandler::send($payment_address, $parsed_tx, $confirmations);


        // check the ledger entries
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $entries = $ledger->findByAccount($account);
        // echo $this->debugShowLedger($ledger, $account)."\n";

        $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        PHPUnit::assertEquals(0, $balances['FOOCOIN']);
        PHPUnit::assertEquals(0.9999107, $balances['BTC']);
    }

    public function testMoveUnconfirmedBalancesWithSend() {
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $account = AccountHandler::getAccount($payment_address);
        $address = $payment_address['address'];

        // put some confirmed FOOCOIN in the account
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['FOOCOIN' => 20, 'BTC' => 1], $payment_address);

        // put some unconfirmed FOOCOIN in the account
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['FOOCOIN' => 80], $payment_address, $with_utxos=true, 'default', $txid='SAMPLETX002', LedgerEntry::UNCONFIRMED);

        // send the entirety of the FOOCOIN
        $confirmations = 0;
        $source = $address;
        $quantity = 100;
        $asset = 'FOOCOIN';
        $transaction_model = app('SampleTransactionsHelper')->createSampleCounterpartySendTransaction($source, $dest=null, $asset, $quantity);
        $parsed_tx = $transaction_model['parsed_tx'];
        AccountHandler::send($payment_address, $parsed_tx, $confirmations);


        // check the ledger entries
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $entries = $ledger->findByAccount($account);
        // echo $this->debugShowLedger($ledger, $account)."\n";

        $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        PHPUnit::assertEquals(0, $balances['FOOCOIN']);
        PHPUnit::assertEquals(0.9999107, $balances['BTC']);
    }

    public function testMoveConfirmedValidBalancesWithReceive() {
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $account = AccountHandler::getAccount($payment_address);
        $address = $payment_address['address'];
        $sample_txid = 'SAMPLETX002';

        // put some unconfirmed FOOCOIN in the account
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['FOOCOIN' => 80, 'BTC' => 0.000035], $payment_address, $with_utxos=true, 'default', $sample_txid, LedgerEntry::UNCONFIRMED);

        // now receive the entirety of the FOOCOIN
        $confirmations = 2;
        $source        = '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j';
        $dest          = $address;
        $quantity      = 80;
        $asset         = 'FOOCOIN';
        $transaction_model = app('SampleTransactionsHelper')->createSampleCounterpartySendTransaction($source, $dest, $asset, $quantity, $sample_txid);

        $parsed_tx = $transaction_model['parsed_tx'];
        AccountHandler::receive($payment_address, $parsed_tx, $confirmations);

        // check the ledger entries
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $entries = $ledger->findByAccount($account);

        // confirmed should have the FOOCOIN
        $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        PHPUnit::assertEquals(80, $balances['FOOCOIN']);
        PHPUnit::assertEquals(0.000035, $balances['BTC']);
    }

    public function testMoveConfirmedInvalidBalancesWithReceive() {
        $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $account = AccountHandler::getAccount($payment_address);
        $address = $payment_address['address'];
        $sample_txid = 'SAMPLETX002';

        // put some unconfirmed FOOCOIN in the account
        app('PaymentAddressHelper')->addBalancesToPaymentAddressAccount(['FOOCOIN' => 80, 'BTC' => 0.000035], $payment_address, $with_utxos=true, 'default', $sample_txid, LedgerEntry::UNCONFIRMED);

        // the confirmed transaction actually sends 0 FOOCOIN (not 80)
        $confirmations = 2;
        $source        = '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j';
        $dest          = $address;
        $quantity      = 0;
        $asset         = 'FOOCOIN';
        $transaction_model = app('SampleTransactionsHelper')->createSampleCounterpartySendTransaction($source, $dest, $asset, $quantity, $sample_txid);

        $parsed_tx = $transaction_model['parsed_tx'];
        AccountHandler::receive($payment_address, $parsed_tx, $confirmations);

        // check the ledger entries
        $ledger = app('App\Repositories\LedgerEntryRepository');
        $entries = $ledger->findByAccount($account);

        // confirmed balances should NOT have the FOOCOIN
        $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        PHPUnit::assertArrayNotHasKey('FOOCOIN', $balances);
        PHPUnit::assertEquals(0.000035, $balances['BTC']);

        // unconfirmed should be cleared of FOOCOIN as well
        $balances = $ledger->accountBalancesByAsset($account, LedgerEntry::UNCONFIRMED);
        PHPUnit::assertEquals(0, $balances['FOOCOIN']);
        PHPUnit::assertEquals(0, $balances['BTC']);
    }

    // ------------------------------------------------------------------------
    
    protected function debugShowLedger($ledger, $account) {
        $out = "";
        $rows = [];
        $all_entries = $ledger->findByAccount($account);
        foreach($all_entries as $entry) {
            $row = [];

            $row['date'] = $entry['created_at']->setTimezone('America/Chicago')->format('Y-m-d H:i:s T');
            $row['amount'] = $entry['amount'];
            $row['asset'] = $entry['asset'];
            $row['type'] = LedgerEntry::typeIntegerToString($entry['type']);
            $row['txid'] = $entry['txid'];

            $rows[] = $row;
        }

        $renderer = new ArrayToTextTable($rows);
        $renderer->showHeaders(true);
        $out .= $renderer->render(true);
        return $out;
    }
}
