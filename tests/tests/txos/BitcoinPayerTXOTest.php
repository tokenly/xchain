<?php

use App\Blockchain\Sender\TXOChooser;
use Tokenly\CurrencyLib\CurrencyUtil;
use \PHPUnit_Framework_Assert as PHPUnit;

class BitcoinPayerTXOTest extends TestCase {

    protected $useDatabase = true;

    public function testBitcoinPayerSimpleSumTest() {
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks();

        $balance = $bitcoin_payer->getBalance('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD');
        PHPUnit::assertEquals(0.235, $balance);

        // this time, with cache
        $balance = $bitcoin_payer->getBalance('1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD');
        PHPUnit::assertEquals(0.235, $balance);
    }

    public function testBitcoinPayerSimpleUTXOSet_1() {
        // just 2 unspent TXOs
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
        ]);

        $balance = $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj');
        PHPUnit::assertEquals(1.2, $balance);
        $balance = $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj');
        PHPUnit::assertEquals(1.2, $balance);
    }

    public function testBitcoinPayerSimpleUTXOSet_2() {
        // 3 unspent TXOs and 1 spent one
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 2.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('12'),
                    'vin' => [
                        ['txid' => $this->makeTXID('10'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 1.5, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                        ['value' => 0.9999, 'address' => '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU',],
                    ],
                ],
        ]);

        // check balances twice (the cache is used for the second time)
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
    }

    public function testBitcoinPayerSimpleUTXOSet_3() {
        // 3 unspent TXOs and 1 spent one (spend first)
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 2.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('12'),
                    'vin' => [
                        ['txid' => $this->makeTXID('10'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 1.5, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                        ['value' => 0.9999, 'address' => '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
        ]);

        // check balances twice (the cache is used for the second time)
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
    }

    public function testBitcoinPayerSimpleUTXOSet_4() {
        // 3 unspent TXOs and 1 spent one
        //   receives vout offset 1
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.0, 'address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j',],
                        ['value' => 2.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'txid' => $this->makeTXID('12'),
                    'vin' => [
                        ['txid' => $this->makeTXID('10'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.5, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                        ['value' => 0.9999, 'address' => '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU',],
                    ],
                ],
        ]);

        // check balances twice (the cache is used for the second time)
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
    }

    public function testBitcoinPayerSimpleUTXOSet_5() {
        // 3 unspent TXOs and 1 spent one
        //   uncached
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'confirmations' => 2,
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.0, 'address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j',],
                        ['value' => 2.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'confirmations' => 2,
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'confirmations' => 2,
                    'txid' => $this->makeTXID('12'),
                    'vin' => [
                        ['txid' => $this->makeTXID('10'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.5, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                        ['value' => 0.9999, 'address' => '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU',],
                    ],
                ],
        ]);

        // check balances twice (the cache is used for the second time)
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
    }

    public function testBitcoinPayerSimpleUTXOSet_6() {
        // 3 unspent TXOs and 1 spent one
        //   uncached => cached
        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'confirmations' => 2,
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.0, 'address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j',],
                        ['value' => 2.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'confirmations' => 2,
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'confirmations' => 2,
                    'txid' => $this->makeTXID('12'),
                    'vin' => [
                        ['txid' => $this->makeTXID('10'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.5, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                        ['value' => 0.9999, 'address' => '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU',],
                    ],
                ],
        ]);

        // check balances twice (the cache is used for the second time)
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));


        list($mock_builder, $mock_calls, $bitcoin_payer) = $this->setupBitcoinPayerMocks([
                [
                    'confirmations' => 6,
                    'txid' => $this->makeTXID('10'),
                    'vin' => [
                        ['txid' => $this->makeTXID('1'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.0, 'address' => '1AAAA1111xxxxxxxxxxxxxxxxxxy43CZ9j',],
                        ['value' => 2.0, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'confirmations' => 6,
                    'txid' => $this->makeTXID('11'),
                    'vin' => [
                        ['txid' => $this->makeTXID('2'), 'vout' => 0,]
                    ],
                    'vout' => [
                        ['value' => 0.2, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                    ],
                ],
                [
                    'confirmations' => 6,
                    'txid' => $this->makeTXID('12'),
                    'vin' => [
                        ['txid' => $this->makeTXID('10'), 'vout' => 1,]
                    ],
                    'vout' => [
                        ['value' => 1.5, 'address' => '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj',],
                        ['value' => 0.9999, 'address' => '1AAAA2222xxxxxxxxxxxxxxxxxxy4pQ3tU',],
                    ],
                ],
        ]);

        // check balances twice (the cache is used for the second time)
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
        PHPUnit::assertEquals(1.7, $bitcoin_payer->getBalance('1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj'));
    }

    // ------------------------------------------------------------------------
    

    protected function setupBitcoinPayerMocks($utxo_defs=null) {
        $override_functions = null;
        if ($utxo_defs !== null) {
            $override_functions = [
                'searchrawtransactions' => $this->buildSearchRawTransactionsFunction($utxo_defs),
            ];
        }

        $mock_calls = new \ArrayObject(['xcpd' => [], 'btcd' => []]);
        $mock_builder = app('CounterpartySenderMockBuilder');
        $mock_calls = $mock_builder->installMockBitcoindClient($this->app, $this, $mock_calls, $override_functions);
        return [$mock_builder, $mock_calls, app('Tokenly\BitcoinPayer\BitcoinPayer')];
    }

    protected function buildSearchRawTransactionsFunction($utxo_defs) {
        return function($address) use ($utxo_defs) {
            return $this->buildTXOs($utxo_defs);
        };
    }

    protected function buildTXOs($txo_defs) {
        $out = [];
        foreach($txo_defs as $txo_def) {
            $out[] = $this->buildTXO($txo_def);
        }
        return $out;
    }

    protected function buildTXO($txo_def) {
        $vins = [];
        foreach ($txo_def['vin'] as $vin_def) {
            $vin = array_replace_recursive(
                [
                    'txid' => '0000000000000000000000000000000000000000000000000000000000001111',
                    'vout' => 0,
                    'scriptSig' => [
                        'asm' => '304502210088deb99e3394737d56bdb1a412ee2660deb24112baa26ec638891ade82ccf042022003355e056ce88f66ffb159184a932227a3176413c75286ff1d01e2e6be86197901 034fa90bcdf199a2879b2f0cc82c097c1553d30f0c24c97cdc79a17d649270c0dd',
                        'hex' => '48304502210088deb99e3394737d56bdb1a412ee2660deb24112baa26ec638891ade82ccf042022003355e056ce88f66ffb159184a932227a3176413c75286ff1d01e2e6be8619790121034fa90bcdf199a2879b2f0cc82c097c1553d30f0c24c97cdc79a17d649270c0dd',
                    ],
                    'sequence' => 100,
                ], $vin_def
            );
            $vins[] = $vin;
        }

        $vouts = [];
        foreach ($txo_def['vout'] as $n => $vout_def) {
            $address = isset($vout_def['address']) ? $vout_def['address'] : '1TEST1111xxxxxxxxxxxxxxxxxxxtjomkj';
            $vout = array_replace_recursive(
                [
                    'value' => 0.23400000000000001,
                    'n' => $n,
                    'scriptPubKey' => [
                        'asm' => 'OP_DUP OP_HASH160 e50575162795cd77366fb80d728e3216bd52deac OP_EQUALVERIFY OP_CHECKSIG',
                        'hex' => '76a914e50575162795cd77366fb80d728e3216bd52deac88ac',
                        'reqSigs' => 1,
                        'type' => 'pubkeyhash',
                        'addresses' => [
                            0 => $address,
                        ],
                    ],
                ], $vout_def
            );
            $vouts[] = $vout;
        }

        $txo = [
            'txid'          => isset($txo_def['txid']) ? $txo_def['txid'] : '000000000000000000000000000000000000000000000000000000000000ffff',
            'version'       => 1,
            'locktime'      => 0,
            'vin'           => $vins,
            'vout'          => $vouts,
            'blockhash'     => '00000000000000000802ad4c4667a0b7a128851e8e357047aea4d742ac911304',
            'confirmations' => isset($txo_def['confirmations']) ? $txo_def['confirmations'] : 1000,
            'time'          => 1439005781,
            'blocktime'     => 1439005781,
            'hex'           => 'deadbeef00000000',
        ];

        return $txo;
    }

    protected function makeTXID($n) {
        return 'f'.str_repeat('a', 59).sprintf('%04d', $n);
    }

}
