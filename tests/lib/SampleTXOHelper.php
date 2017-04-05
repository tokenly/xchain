<?php

use App\Models\TXO;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\TXORepository;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Tokenly\CurrencyLib\CurrencyUtil;

/**
*  SampleTXOHelper
*/
class SampleTXOHelper
{

    static $SAMPLE_TXID = 0;

    public static function resetSampleTXID() {
        self::$SAMPLE_TXID = 0;
    }

    public function __construct(TXORepository $txo_repository) {
        $this->txo_repository = $txo_repository;
    }

    public function createSampleTXO($payment_address=null, $overrides=[]) {
        if ($payment_address === null) {
            $payment_address = app('PaymentAddressHelper')->createSamplePaymentAddress();
        }

        $account = AccountHandler::getAccount($payment_address, 'default');

        // build a real script
        $script = ScriptFactory::scriptPubKey()->payToAddress(AddressFactory::fromString($payment_address['address']));

        $attributes = array_merge([
            'txid'   => $this->nextTXID(),
            'n'      => 0,
            'script' => $script->getHex(),
            'amount' => 54321,
            'type'   => TXO::CONFIRMED,
            'spent'  => false,
            'green'  => false,
        ], $overrides);
        $txo_model = $this->txo_repository->create($payment_address, $account, $attributes);
        return $txo_model;
    }

    public function createSampleTXORecords($specs) {
        $prototype_record = [
            'txid'   => '',
            'n'      => 0,
            'amount' => 0,
            'type'   => TXO::CONFIRMED,
            'spent'  => false,
            'green'  => false,
        ];

        $records = [];
        foreach($specs as $spec) {
            if (!is_array($spec)) {
                $spec = ['amount' => $spec];
            }
            $records[] = array_merge($prototype_record, ['txid' => $this->nextTXID()], $spec);
        }
        return $records;
    }

    public function nextTXID() {
        return str_repeat('1', 60).sprintf('%04d', (++self::$SAMPLE_TXID));
    }

    public function debugDumpCoinGroup($coin_group) {
        $amounts = '';
        foreach($coin_group['txos'] as $txo) {
            $amounts = ltrim($amounts.','.$txo['amount'], ',');
        }

        return 'Fee: '.$coin_group['fee'].' FeePerByte: '.round($coin_group['fee_per_byte'],1).' | Change: '.$coin_group['change_amount'].' | Count: '.count($coin_group['txos']).' | Size: '.$coin_group['size'].' | Amts: '.$amounts;
    }

    public function debugDumpTXORecords($txo_records) {
        // build a table
        $bool = function($val) { return $val ? '<info>true</info>' : '<comment>false</comment>'; };
        $headers = ['txid', 'n', 'amount', 'type', 'spent', 'green'];
        $rows = [];
        foreach($txo_records as  $txo) {
            $pieces = explode('.', CurrencyUtil::satoshisToFormattedString($txo['amount']));
            if (count($pieces) == 2) {
                $amount = $pieces[0].".".str_pad($pieces[1], 8, '0', STR_PAD_RIGHT);
            } else {
                $amount = $amount.".00000000";
            }

            $type = TXO::typeIntegerToString($txo['type']);
            $rows[] = [$txo['txid'], $txo['n'], $amount, $type, $bool($txo['spent']), $bool($txo['green'])];
        }

        $output = new BufferedOutput();
        $output->setDecorated(true);
        $table = new Table($output);
        $table->setHeaders($headers)->setRows($rows)->setStyle('default')->render();
        return $output->fetch();
    }
}