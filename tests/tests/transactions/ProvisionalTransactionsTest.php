<?php

use App\Providers\Accounts\Facade\AccountHandler;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class ProvisionalTransactionsTest extends TestCase {

    protected $useDatabase = true;

    public function testProvisionalTransactions() {
        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');

        // setup monitors
        $address_helper = app('MonitoredAddressHelper');
        $monitor_1 = $address_helper->createSampleMonitoredAddress(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);
        $monitor_2 = $address_helper->createSampleMonitoredAddress(null, ['address' => '1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz']);

        // receive unconfirmed transactions
        $parsed_txs = $this->receiveUnconfirmedTransactions(5);

        // check that the provisional transactions are present
        $loaded_transactions = $provisional_tx_repository->findAll();
        PHPUnit::assertCount(5, $loaded_transactions);

        // now confirm a transaction (with 1 confirmation)
        $this->sendConfirmationEvents(1, $parsed_txs);

        // still 5 provisional transactions
        PHPUnit::assertCount(5, $provisional_tx_repository->findAll());


        // now confirm a transaction (with 2 confirmations)
        $this->sendConfirmationEvents(2, $parsed_txs);

        // all provisional transactions are now cleared
        PHPUnit::assertCount(0, $provisional_tx_repository->findAll());
    }

    public function testUnconfirmingAnInvalidatedTransaction() {
        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');
        $notification_repository = app('App\Repositories\NotificationRepository');

        // setup monitors
        $address_helper = app('MonitoredAddressHelper');
        $monitor_1 = $address_helper->createSampleMonitoredAddress(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // receive unconfirmed transactions
        $parsed_txs = $this->receiveUnconfirmedTransactions(1);

        // now confirm a malleated version of the transaction
        $malleated_tx = $parsed_txs[0];
        $malleated_tx['txid'] = str_repeat('0', 54).'MALLEATED1';
        $malleated_txs = [$malleated_tx];

        // now confirm the malleated transaction (with 2 confirmations)
        $this->sendConfirmationEvents(2, $malleated_txs);

        // check for invalidation notification
        $notification_models = $notification_repository->findByMonitoredAddressId($monitor_1['id'])->toArray();
        $invalidation_notification = array_slice($notification_models, -2, 1)[0];
        $invalidation_notification_details = $invalidation_notification['notification'];
        PHPUnit::assertEquals('invalidation', $invalidation_notification_details['event']);
        PHPUnit::assertEquals($parsed_txs[0]['txid'], $invalidation_notification_details['invalidTxid']);
        PHPUnit::assertEquals($malleated_txs[0]['txid'], $invalidation_notification_details['replacingTxid']);

        // check that the provisional transaction was removed
        $removed_tx = $provisional_tx_repository->findByTXID($parsed_txs[0]['txid']);
        PHPUnit::assertEmpty($removed_tx);
    }


    public function testIncomingInvalidatedTransactionUpdatesLedgerEntries() {
        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');
        $notification_repository = app('App\Repositories\NotificationRepository');
        $ledger_entry_repository = app('App\Repositories\LedgerEntryRepository');

        // setup monitors
        $payment_address_helper = app('PaymentAddressHelper');
        $payment_address_one = $payment_address_helper->createSamplePaymentAddressWithoutInitialBalances(null, ['address' => '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD']);

        // receive unconfirmed transactions
        $parsed_txs = $this->receiveUnconfirmedTransactions(1);

        // check accounts
        $account_one = AccountHandler::getAccount($payment_address_one, 'default');
        $balance = $ledger_entry_repository->accountBalancesByAsset($account_one, null);
        PHPUnit::assertEquals(0.004, $balance['unconfirmed']['BTC']);

        // now confirm a malleated version of the transaction
        $malleated_tx = $parsed_txs[0];
        $malleated_tx['txid'] = str_repeat('0', 54).'MALLEATED1';
        $malleated_txs = [$malleated_tx];

        // now confirm the malleated transaction (with 2 confirmations)
        $this->sendConfirmationEvents(2, $malleated_txs);

        // check for invalidation notification
        $balance = $ledger_entry_repository->accountBalancesByAsset($account_one, null);
        PHPUnit::assertEquals(0.004, $balance['confirmed']['BTC']);
        PHPUnit::assertArrayNotHasKey('BTC', $balance['unconfirmed']);
    }


    public function testInvalidatedProvisionalTransactionsNotifications() {
        $notification_helper = app('NotificationHelper')->recordNotifications();

        $provisional_tx_repository = app('App\Repositories\ProvisionalTransactionRepository');

        // setup monitors
        $address_helper = app('MonitoredAddressHelper');
        $monitor_1 = $address_helper->createSampleMonitoredAddress(null, ['address' => 'RECIPIENT01']);
        $monitor_2 = $address_helper->createSampleMonitoredAddress(null, ['address' => 'UNKNOWN']);

        // receive unconfirmed transactions
        $parsed_txs = $this->receiveUnconfirmedTransactions(5, 'sample_btc_parsed_02.json');
        Log::debug("\$parsed_txs=".json_encode($this->parsedTransactionSummaries($parsed_txs), 192));

        // check that the provisional transactions are present
        $loaded_transactions = $provisional_tx_repository->findAll();
        PHPUnit::assertCount(5, $loaded_transactions);

        // the 5th confirmed transaction has a new txid
        $parsed_txs[4] = $this->makeInvalidatingTransaction($parsed_txs[4], $parsed_txs[4]);

        // now confirm a transaction (with 1 confirmation)
        $this->sendConfirmationEvents(1, $parsed_txs);

        // still 5 provisional transactions
        PHPUnit::assertCount(5, $provisional_tx_repository->findAll());

        // now confirm a transaction (with 2 confirmations)
        $this->sendConfirmationEvents(2, $parsed_txs);

        // all provisional transactions are now cleared
        PHPUnit::assertCount(0, $provisional_tx_repository->findAll());

        // one invalidation notice should be sent
        $notifications = $notification_helper->getAllNotifications();
        PHPUnit::assertEquals(15, $notification_helper->countNotificationsByEventType($notifications, 'receive'));
        PHPUnit::assertEquals(1, $notification_helper->countNotificationsByEventType($notifications, 'invalidation'));
    }

    // ------------------------------------------------------------------------------------------------------------------------------------------------

    protected function receiveUnconfirmedTransactions($count=5, $filename='sample_btc_parsed_01.json') {

        $parsed_txs = [];
        for ($i=0; $i < $count; $i++) { 
            $parsed_tx = $this->buildSampleTransactionVars($i, $filename);
            $parsed_txs[] = $parsed_tx;

            Event::fire('xchain.tx.received', [$parsed_tx, 0, null, null, ]);
        }

        return $parsed_txs;
    }

    protected function buildSampleTransactionVars($i, $filename) {
        $tx_helper = app('SampleTransactionsHelper');

        $parsed_tx = $tx_helper->loadSampleTransaction($filename, ['txid' => str_repeat('0', 63).($i+1)]);
        foreach ($parsed_tx['bitcoinTx']['vin'] as $offset => $vin) {
            $parsed_tx['bitcoinTx']['vin'][$offset]['txid'] = str_repeat('a', 62).($i).($offset+1);
            $parsed_tx['bitcoinTx']['vin'][$offset]['vout'] = 0;
        }

        return $parsed_tx;
    }

    protected function makeInvalidatingTransaction($replacing_parsed_tx, $target_parsed_tx) {
        foreach ($target_parsed_tx['bitcoinTx']['vin'] as $offset => $vin) {
            $replacing_parsed_tx['bitcoinTx']['vin'][$offset]['txid'] = $vin['txid'];
            $replacing_parsed_tx['bitcoinTx']['vin'][$offset]['vout'] = $vin['vout'];
            $replacing_parsed_tx['txid'] = str_repeat('b', 63).'f';
        }

        return $replacing_parsed_tx;
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

    protected function parsedTransactionSummaries($parsed_txs) {
        $out = [];
        foreach($parsed_txs as $parsed_tx) {
            $vins = [];
            foreach ($parsed_tx['bitcoinTx']['vin'] as $vin) {
                $vins[] = [
                    'txid' => $vin['txid'],
                    'vout' => $vin['vout'],
                ];
            }
            $out[] = [
                'txid' => $parsed_tx['txid'],
                'vins' => $vins,
            ];
        }

        return $out;
    }

}
