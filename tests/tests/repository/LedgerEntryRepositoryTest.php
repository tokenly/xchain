<?php

use App\Models\Account;
use App\Models\LedgerEntry;
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
        $repo->addCredit(100, 'BTC', $account_one, LedgerEntry::CONFIRMED, $txid);

        // transfer
        $repo->transfer(20, 'BTC', $account_one, $account_two, LedgerEntry::CONFIRMED, $api_call);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account_one)));
        PHPUnit::assertCount(2, $loaded_models);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $loaded_models[0]['amount']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(-20), $loaded_models[1]['amount']);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account_two)));
        PHPUnit::assertCount(1, $loaded_models);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(20), $loaded_models[0]['amount']);
    }

    /**
     * @expectedException        Exception
     * @expectedExceptionMessage Balance of 100 was insufficient to debit 110 (confirmed) BTC from Test Account
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
        $repo->addCredit(100, 'BTC', $account_one, LedgerEntry::CONFIRMED, $txid);

        // transfer
        $repo->transfer(110, 'BTC', $account_one, $account_two, LedgerEntry::CONFIRMED, $api_call);
    }

    /**
     * @expectedException        Exception
     * @expectedExceptionMessage Balance of 0 was insufficient to debit 100 (confirmed) BTC from Test Account
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
        $repo->addDebit(100, 'BTC', $account_one, LedgerEntry::CONFIRMED, $txid);
    }

    public function testAddCreditsAndDebits() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $account = app('AccountHelper')->newSampleAccount();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC', $account, LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(200, 'BTC', $account, LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit( 300, 'BTC', $account, LedgerEntry::CONFIRMED, $txid);

        $loaded_models = array_values(iterator_to_array($repo->findByAccount($account)));
        PHPUnit::assertCount(3, $loaded_models);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(100), $loaded_models[0]['amount']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(200), $loaded_models[1]['amount']);
        PHPUnit::assertEquals(CurrencyUtil::valueToSatoshis(-300), $loaded_models[2]['amount']);
    }

    public function testAccountBalance() {
        $helper = $this->createRepositoryTestHelper();
        $helper->cleanup();

        $account = app('AccountHelper')->newSampleAccount();
        $txid = 'deadbeef00000000000000000000000000000000000000000000000000000001';

        // add credit
        $repo = app('App\Repositories\LedgerEntryRepository');
        $repo->addCredit(100, 'BTC', $account, LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(200, 'BTC', $account, LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(  50, 'BTC', $account, LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(  3, 'BTC', $account, LedgerEntry::UNCONFIRMED, $txid);

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
        $repo->addCredit(100, 'BTC',     $account,     LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(200, 'BTC',     $account,     LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 80, 'TOKENLY', $account,     LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(  50, 'BTC',     $account,     LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 23, 'SOUP',    $account,     LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(   3, 'SOUP',    $account,     LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(  9, 'SOUP',    $account_two, LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(  2, 'BTC',     $account,     LedgerEntry::UNCONFIRMED, $txid);
        $repo->addCredit(  2, 'BTC',     $account_two, LedgerEntry::UNCONFIRMED, $txid);

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
        $repo->addCredit(100, 'BTC',     $account,       LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(200, 'BTC',     $account,       LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 80, 'TOKENLY', $account,       LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(  50, 'BTC',     $account,       LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 23, 'SOUP',    $account,       LedgerEntry::CONFIRMED, $txid);
        $repo->addDebit(   3, 'SOUP',    $account,       LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(  9, 'SOUP',    $account_two,   LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit( 11, 'SOUP',    $account_two,   LedgerEntry::UNCONFIRMED, $txid);
        $repo->addCredit(  8, 'BTC',     $other_account, LedgerEntry::CONFIRMED, $txid);
        $repo->addCredit(  2, 'BTC',     $account,       LedgerEntry::UNCONFIRMED, $txid);
        $repo->addCredit(  3, 'BTC',     $account_two,   LedgerEntry::UNCONFIRMED, $txid);

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

}
