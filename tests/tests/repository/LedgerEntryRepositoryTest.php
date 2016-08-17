<?php

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use Carbon\Carbon;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class LedgerEntryRepositoryTest extends TestCase {

    protected $useDatabase = true;

    public function testLedgerEntryRepository()
    {
        $helper = $this->createRepositoryTestHelper();

        $helper->testLoad();
        $helper->cleanup()->testDelete();
        $helper->cleanup()->testFindAll();
    }

    public function testTransferWithAPICall() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account_one = app('AccountHelper')->newSampleAccount($address);
        $account_two = app('AccountHelper')->newSampleAccount($address, 'Account Two');
        $api_call = app('APICallHelper')->newSampleAPICall();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC', $account_one, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        // transfer
        $repo->transfer(20, 'BTC', $account_one, $account_two, LedgerEntry::CONFIRMED, $api_call);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account_one)));
        PHPUnit::assertCount(2, $loaded_models);
        PHPUnit::assertEquals(100, $loaded_models[0]['amount']);
        PHPUnit::assertEquals(-20, $loaded_models[1]['amount']);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account_two)));
        PHPUnit::assertCount(1, $loaded_models);
        PHPUnit::assertEquals(20, $loaded_models[0]['amount']);
    }

    /**
     * @expectedException        Exception
     * @expectedExceptionMessage Balance of 100 (10000000000 sat.) was insufficient to debit 110 (11000000000 sat.)  (confirmed) BTC from Test Account
     */
    public function testTransferBalanceError() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account_one = app('AccountHelper')->newSampleAccount($address);
        $account_two = app('AccountHelper')->newSampleAccount($address, 'Account Two');
        $api_call = app('APICallHelper')->newSampleAPICall();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC', $account_one, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        // transfer
        $repo->transfer(110, 'BTC', $account_one, $account_two, LedgerEntry::CONFIRMED, $api_call);
    }

    /**
     * @expectedException        Exception
     * @expectedExceptionMessage Balance of 0 (0 sat.) was insufficient to debit 100 (10000000000 sat.)  (confirmed) BTC from Test Account
     */
    public function testDebitError() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account_one = app('AccountHelper')->newSampleAccount($address);
        $api_call = app('APICallHelper')->newSampleAPICall();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add debit (to empty account)
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addDebit(100, 'BTC', $account_one, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
    }

    public function testAddCreditsAndDebits() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $account = app('AccountHelper')->newSampleAccount();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(200, 'BTC', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addDebit( 300, 'BTC', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account)));
        PHPUnit::assertCount(3, $loaded_models);
        PHPUnit::assertEquals(100, $loaded_models[0]['amount']);
        PHPUnit::assertEquals(200, $loaded_models[1]['amount']);
        PHPUnit::assertEquals(-300, $loaded_models[2]['amount']);
    }

    public function testAccountBalance() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $account = app('AccountHelper')->newSampleAccount();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(200, 'BTC', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addDebit(  50, 'BTC', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  3, 'BTC', $account, LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        PHPUnit::assertEquals(250, $repo->accountBalance($account, 'BTC', LedgerEntry::CONFIRMED));
        PHPUnit::assertEquals(253, $repo->accountBalance($account, 'BTC', null));
    }

    public function testAccountBalanceByAsset() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account = app('AccountHelper')->newSampleAccount($address);
        $account_two = app('AccountHelper')->newSampleAccount($address, 'Account Two');
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC',     $account,     LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(200, 'BTC',     $account,     LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit( 80, 'TOKENLY', $account,     LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addDebit(  50, 'BTC',     $account,     LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit( 23, 'SOUP',    $account,     LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addDebit(   3, 'SOUP',    $account,     LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  9, 'SOUP',    $account_two, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  2, 'BTC',     $account,     LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  2, 'BTC',     $account_two, LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        PHPUnit::assertEquals([
                'BTC'     => 250,
                'SOUP'    => 20,
                'TOKENLY' => 80,
        ], $repo->accountBalancesByAsset($account, LedgerEntry::CONFIRMED));


        PHPUnit::assertEquals([
            'unconfirmed' => [
                'BTC' => 2,
            ],
            'confirmed' => [
                'BTC'     => 250,
                'SOUP'    => 20,
                'TOKENLY' => 80,
            ],
            'sending' => [],
        ], $repo->accountBalancesByAsset($account, null));
    }


    public function testCombinedAccountBalancesByAsset() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $address_two = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account = app('AccountHelper')->newSampleAccount($address);
        $account_two = app('AccountHelper')->newSampleAccount($address, 'Account Two');
        $other_account = app('AccountHelper')->newSampleAccount($address_two);
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC',     $account,       LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(200, 'BTC',     $account,       LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit( 80, 'TOKENLY', $account,       LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addDebit(  50, 'BTC',     $account,       LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit( 23, 'SOUP',    $account,       LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addDebit(   3, 'SOUP',    $account,       LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  9, 'SOUP',    $account_two,   LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit( 11, 'SOUP',    $account_two,   LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  8, 'BTC',     $other_account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  2, 'BTC',     $account,       LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);
        $repo->addCredit(  3, 'BTC',     $account_two,   LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER, $txid);

        PHPUnit::assertEquals([
            'BTC'     => 250,
            'SOUP'    => 29,
            'TOKENLY' => 80,
        ], $repo->combinedAccountBalancesByAsset($address, LedgerEntry::CONFIRMED));

        PHPUnit::assertEquals([
            'unconfirmed' => [
                'BTC'  => 5,
                'SOUP' => 11,
            ],
            'confirmed' => [
                'BTC'     => 250,
                'SOUP'    => 29,
                'TOKENLY' => 80,
            ],
            'sending' => [],
        ], $repo->combinedAccountBalancesByAsset($address, null));

    }

    public function testFindLedgerEntriesByTXID() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $address_2 = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account = app('AccountHelper')->newSampleAccount($address);
        $account_two = app('AccountHelper')->newSampleAccount($address_2);
        $txid_1 = 'deadbeef00000000000000000000000000000000000000000000000000000001';
        $txid_2 = 'deadbeef00000000000000000000000000000000000000000000000000000002';

        // add entries
        $entries = [];
        $repo = app('App\Repositories\LedgerEntryRepository');
        $entries[] = $repo->addCredit(100, 'BTC',     $account,     LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_RECEIVE, $txid_1);
        $entries[] = $repo->addCredit(100, 'BTC',     $account,     LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_SEND,    $txid_1);
        $entries[] = $repo->addCredit(200, 'BTC',     $account,     LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_OTHER,   $txid_1);
        $entries[] = $repo->addCredit( 23, 'SOUP',    $account,     LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_OTHER,   $txid_1);
        $entries[] = $repo->addDebit(   3, 'SOUP',    $account,     LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_OTHER,   $txid_1);
        $entries[] = $repo->addCredit(  9, 'SOUP',    $account_two, LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_OTHER,   $txid_2);
        $entries[] = $repo->addDebit(   2, 'SOUP',    $account_two, LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_OTHER,   $txid_2);


        // all for txid 1
        $this->assertFound([0,1,2,3,4], $entries, $repo->findByTXID($txid_1, $address['id']));
        $this->assertFound([0,1,2,3,4], $entries, $repo->findByTXID($txid_1));

        // all for txid 2
        $this->assertFound([5,6], $entries, $repo->findByTXID($txid_2));
        $this->assertFound([5,6], $entries, $repo->findByTXID($txid_2, $address_2['id']));

        // filter by type
        $this->assertFound([0,1,3,4], $entries, $repo->findByTXID($txid_1, $address['id'], LedgerEntry::CONFIRMED));
        $this->assertFound([2], $entries, $repo->findByTXID($txid_1, $address['id'], LedgerEntry::UNCONFIRMED));

        // filter by direction
        $this->assertFound([0], $entries, $repo->findByTXID($txid_1,     $address['id'], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_RECEIVE));
        $this->assertFound([1], $entries, $repo->findByTXID($txid_1,     $address['id'], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_SEND));
        $this->assertFound([3,4], $entries, $repo->findByTXID($txid_1,   $address['id'], LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_OTHER));
        $this->assertFound([0], $entries, $repo->findByTXID($txid_1,     $address['id'], null,                   LedgerEntry::DIRECTION_RECEIVE));
        $this->assertFound([1], $entries, $repo->findByTXID($txid_1,     $address['id'], null,                   LedgerEntry::DIRECTION_SEND));
        $this->assertFound([2,3,4], $entries, $repo->findByTXID($txid_1, $address['id'], null,                   LedgerEntry::DIRECTION_OTHER));

    }

    public function testFindTransactionIDsByType() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        $account = app('AccountHelper')->newSampleAccount($address);
        $txids = [];
        for ($i=0; $i < 99; $i++) { 
            $txids[] = 'deadbeef00000000000000000000000000000000000000000000000000000'.sprintf('%03d', $i);
        }

        // add entries
        $entries = [];
        $repo = app('App\Repositories\LedgerEntryRepository');
        $entries[] = $repo->addCredit(5,   'BTC',     $account,     LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_RECEIVE, $txids[0]);
        $entries[] = $repo->addCredit(10,  'BTC',     $account,     LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_RECEIVE, $txids[1]);
        $entries[] = $repo->addCredit(11,  'BTC',     $account,     LedgerEntry::SENDING,     LedgerEntry::DIRECTION_RECEIVE, $txids[2]);
        $entries[] = $repo->addDebit (11,  'BTC',     $account,     LedgerEntry::SENDING,     LedgerEntry::DIRECTION_RECEIVE, $txids[2]);
        $entries[] = $repo->addCredit(12,  'BTC',     $account,     LedgerEntry::SENDING,     LedgerEntry::DIRECTION_RECEIVE, $txids[3]);


        // find unreconciled transaction entries
        $results = $repo->findTransactionIDsByType($account);
        PHPUnit::assertCount(3, $results, json_encode($results, 192));
        PHPUnit::assertEquals(10, $results[0]['total']);
        PHPUnit::assertEquals($txids[1], $results[0]['txid']);
        PHPUnit::assertEquals($txids[2], $results[1]['txid']);
        PHPUnit::assertEquals($txids[3], $results[2]['txid']);
    }

    public function testExpireTimedOutTransactionEntries() {
        // install mocks
        app('CounterpartySenderMockBuilder')->installMockCounterpartySenderDependencies($this->app, $this, ['getrawtransaction' => function($txid) {
            if (substr($txid, 0, 12) == 'deadbeef0000') {
                // throw a -5 error
                throw new Exception("Bitcoind could not find this txid", -5);
            }
            // everything else is ok
            return '000000001';
        }]);

        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
        $account = AccountHandler::getAccount($address);
        $txids = [];
        for ($i=0; $i < 99; $i++) { 
            $txids[] = 'deadbeef00000000000000000000000000000000000000000000000000000'.sprintf('%03d', $i);
        }

        // add entries
        $entries = [];
        $repo = app('App\Repositories\LedgerEntryRepository');
        $entries[] = $repo->addCredit(5,   'BTC',     $account,     LedgerEntry::CONFIRMED,   LedgerEntry::DIRECTION_RECEIVE, $txids[0]);
        $entries[] = $repo->addCredit(10,  'BTC',     $account,     LedgerEntry::UNCONFIRMED, LedgerEntry::DIRECTION_RECEIVE, $txids[1]);
        $entries[] = $repo->addCredit(11,  'BTC',     $account,     LedgerEntry::SENDING,     LedgerEntry::DIRECTION_RECEIVE, $txids[2]);
        $entries[] = $repo->addDebit (11,  'BTC',     $account,     LedgerEntry::SENDING,     LedgerEntry::DIRECTION_RECEIVE, $txids[2]);
        $entries[] = $repo->addCredit(12,  'BTC',     $account,     LedgerEntry::SENDING,     LedgerEntry::DIRECTION_RECEIVE, $txids[3]);

        // time out all the entries
        DB::table('ledger_entries')
            ->where('payment_address_id', $address['id'])
            ->update(['created_at' => Carbon::now()->subHours(5)->toDateTimeString()]);

        // make txids 0 and 2 legit
        $transaction_helper = app('SampleTransactionsHelper');
        $transaction = $transaction_helper->createSampleTransaction('sample_btc_parsed_01.json', ['txid' => $txids[0]]);
        $transaction = $transaction_helper->createSampleTransaction('sample_btc_parsed_01.json', ['txid' => $txids[2]]);


        // call the console command
        $this->app['Illuminate\Contracts\Console\Kernel']->call('xchain:expire-pending-transactions', ['payment-address-uuid' => $address['uuid']]);

        // only 3 entries remain
        $all_entries = $repo->findByAccount($account);
        PHPUnit::assertCount(3, $all_entries);

        // find only the legit send left (txids[2])
        $results = $repo->findTransactionIDsByType($account);
        PHPUnit::assertCount(1, $results);
    }


    public function testLargeIndivisibleAssets() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $account = app('AccountHelper')->newSampleAccount();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        // 100,000,000,000
        $repo->addCredit(100000000000, 'BIGASSET', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_RECEIVE, $txid);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account)));
        PHPUnit::assertCount(1, $loaded_models);
        PHPUnit::assertGreaterThan(0, $loaded_models[0]['amount']);
        PHPUnit::assertEquals(100000000000, $loaded_models[0]['amount']);


        $repo->addCredit(0.00000001, 'BIGASSET', $account, LedgerEntry::CONFIRMED, LedgerEntry::DIRECTION_RECEIVE, $txid);
        $balances = $repo->accountBalancesByAsset($account, LedgerEntry::CONFIRMED);
        PHPUnit::assertEquals(100000000000.00000001, $balances['BIGASSET']);
    }


    // ------------------------------------------------------------------------
    
    protected function createRepositoryTestHelper() {
        $create_model_fn = function() {
            $address = app('PaymentAddressHelper')->createSamplePaymentAddressWithoutInitialBalances();
            $account = app('AccountHelper')->newSampleAccount($address);
            return $this->app->make('LedgerEntryHelper')->newSampleLedgerEntry($account);
        };
        $helper = new RepositoryTestHelper($create_model_fn, $this->app->make('App\Repositories\LedgerEntryRepository'));
        return $helper;
    }

    protected function sampleEvent($account) {
        return app('BotEventHelper')->newSampleBotEvent($account);
    }

    protected function assertFound($expected_offsets, $sample_entries, $chosen_entries) {
        $expected_entry_arrays = [];
        foreach($expected_offsets as $expected_offset) {
            $expected_entry_arrays[] = $sample_entries[$expected_offset]->toArray();
        }

        $actual_amounts = [];
        $chosen_entry_arrays = [];
        foreach($chosen_entries as $chosen_entry) {
            $chosen_entry_arrays[] = ($chosen_entry ? $chosen_entry->toArray() : null);
            $actual_amounts[] = CurrencyUtil::satoshisToFormattedString($chosen_entry['amount']).' '.$chosen_entry['asset'];
        }

        PHPUnit::assertEquals($expected_entry_arrays, $chosen_entry_arrays, "Did not find the expected offsets of ".json_encode($expected_offsets).'. Actual amounts were '.json_encode($actual_amounts));
    }


}
