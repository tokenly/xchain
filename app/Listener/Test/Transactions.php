<?php

namespace App\Listener\Test;

use Tokenly\CounterpartyTransactionParser\Parser;
use \Exception;

/*
* Transactions
*/
class Transactions
{

    public function __construct(Parser $parser) {
        $this->parser = $parser;
    }

    public function formatTxAsXstalkerJobData($raw_tx) {
        return [
            'ver' => 1,
            'ts'  => time(),
            'tx'  => $raw_tx,
        ];
    }

    public function getSampleCounterpartyTransaction() {
        // 533.83451959 LTBCOIN
        return json_decode('{"txid":"1886737bb2a4be1af89b1d0e5af427ef2a7fc439e2ed10a42d3efeb1f71b69aa","version":1,"locktime":0,"vin":[{"txid":"26bc3e4933c68d503d0c24bc039a64ace18b2899dc54f799a80f20fc047d7688","vout":2,"scriptSig":{"asm":"3045022100b1514287d58b56c8bb2df00349cdebd1c7fded0d7fe92320743dd631836ef62002200f56062249ce5a64b81d0921ac65151633c1c54dea3a93bc51844863d201d87301 0370a00e36f0ca37c2d80631e0209ede134dbd927d50a364c6747f9c5f3c2c7a9c"},"sequence":4294967295,"n":0,"addr":"1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz","valueSat":9114000,"value":0.09114,"doubleSpentTxID":null}],"vout":[{"value":"0.00001250","n":0,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 c56cb39f9b289c0ec4ef6943fa107c904820fe09 OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD"]}},{"value":"0.00001250","n":1,"scriptPubKey":{"asm":"1 0370a00e36f0ca37c2d80631e0209ede134dbd927d50a364c6747f9c5f3c2c7a9c 1c434e5452505254590000000000000000d806c1d50000000c6de6d53700000000 2 OP_CHECKMULTISIG","reqSigs":1,"type":"multisig","addresses":["1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz","1HT7xU2Ngenf7D4yocz2SAcnNLW7rK8d4E"]}},{"value":"0.09108000","n":2,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 9b2c0c5f30a2dde09c2a9d3618ba390c9a688754 OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1F9UWGP1YwZsfXKogPFST44CT3WYh4GRCz"]},"spentTxId":"fbd318eb157becef3e26460756eaee2f4910d8995d23414ce1938563ecc61784","spentIndex":0,"spentTs":1416091287}],"blockhash":"00000000000000000347e702fdc4d6ed74dca01844857deb5fec560c25b14d51","confirmations":25,"time":1416091287,"blocktime":1416091287,"valueOut":0.091105,"size":306,"valueIn":0.09114,"fees":0.000035}', true);
    }

    public function getSampleBitcoinTransaction() {
        return json_decode('{"txid":"cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741","version":1,"locktime":0,"vin":[{"txid":"cc669b824186886407ad7edd46796437e20ad73c89080420c45e5803f917228d","vout":2,"scriptSig":{"asm":"3045022100a37bcfd3087fa4ba9480ce09c7adf02ba3ce2208d6170b42e50b5b2633b91ee6022025d409d3d9dae0a159982c7ab079787948b6b6c5f87fa583d3886ebf1e074c8901 02f4aef682535628a7e0492b2b5db1aa312348c3095e0258e26b275b25b10290e6"},"sequence":4294967295,"n":0,"addr":"1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1","valueSat":781213,"value":0.00781213,"doubleSpentTxID":null}],"vout":[{"value":"0.00400000","n":0,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 c56cb39f9b289c0ec4ef6943fa107c904820fe09 OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD"]},"spentTxId":"e90bc279294d704d09b227ad0e37459f61cccb85008605656dc8b024235eefe8","spentIndex":2,"spentTs":1403958484},{"value":"0.00361213","n":1,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 6ca4b6b20eac497e9ca94489c545a3372bdd2fa7 OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1"]},"spentTxId":"3587bfa8d96c10b6696728651900db2ad6b41321ea44f26693de4f90d2b63526","spentIndex":0,"spentTs":1405081243}],"blockhash":"00000000000000003a1e5abc2d7af7f38a614d2fcbafe309b7e8aa147d508a9c","confirmations":22369,"time":1403957896,"blocktime":1403957896,"valueOut":0.00761213,"size":226,"valueIn":0.00781213,"fees":0.0002}', true);
    }

    public function getSampleEarlyCounterpartyTransaction() {
        return json_decode('{"txid":"6f5da639314c4fba797d57b9789e1bb8ef71227d8e8bff8e00899635559ad4e3","version":1,"locktime":0,"vin":[{"txid":"08ee2d0a9bd6507250a3706663b7cdd4bc11832a5abe905825a894b7a14d0382","vout":1,"scriptSig":{"asm":"3045022100984f6392254d6a67750fa2391f1785aa983496bd048f4fab19c25a0194d21fee0220682c6823468a1b7a00739ff90eb6369d060ffb4d3bd29f8430d3a45a821895aa01 025355ad7d188adc68fdea44e44c380c813d7df076f035e36b18c6f091954c6282"},"sequence":4294967295,"n":0,"addr":"15ra6w1RmFrL7q3VeJniQ55W91QGjehbRW","valueSat":9471349,"value":0.09471349,"doubleSpentTxID":null}],"vout":[{"value":"0.00007800","n":0,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 dd82adfd30738d97c94c6db5b057bf8de81302af OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1MCEtBB5X4ercRsvq2GmgysZ9ZDsqj8Xh7"]}},{"value":"0.00007800","n":1,"scriptPubKey":{"asm":"1 025355ad7d188adc68fdea44e44c380c813d7df076f035e36b18c6f091954c6282 1c434e5452505254590000000000000000001c125a000000000000000100000000 2 OP_CHECKMULTISIG","reqSigs":1,"type":"multisig","addresses":["15ra6w1RmFrL7q3VeJniQ55W91QGjehbRW","1HT7xU2Ngenf7D4yocz2SAcnNLW7rK8d4E"]}},{"value":"0.09445749","n":2,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 35408483fe1c677a4542279d99df10b8f34b9adb OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["15ra6w1RmFrL7q3VeJniQ55W91QGjehbRW"]},"spentTxId":"6c8c1d0dd52d7716e05fccd679e0b2990f82969adf2aa1db4cedfe60b922bfa3","spentIndex":0,"spentTs":1410911908}],"blockhash":"000000000000000000099527c0311fa6f4ef51aec43ae5009e641406d3566a75","confirmations":9833,"time":1410911908,"blocktime":1410911908,"valueOut":0.09461349,"size":306,"valueIn":0.09471349,"fees":0.0001}', true);
    }

    public function sampleEarlyAssetInfo() {
        return json_decode($_j=<<<EOT
    {
        "asset": "EARLY",
        "callable": false,
        "call_date": 0,
        "description": "http://letstalkbitcoin.com/blog/post/tcv",
        "owner": "1MCEtBB5X4ercRsvq2GmgysZ9ZDsqj8Xh7",
        "call_price": 0,
        "divisible": false,
        "supply": 2401,
        "locked": true,
        "issuer": "1MCEtBB5X4ercRsvq2GmgysZ9ZDsqj8Xh7"
    }
EOT
, true);
    }

    public function sampleLTBCoinAssetInfo() {
        return json_decode($_j=<<<EOT
    {
        "asset": "LTBCOIN",
        "callable": false,
        "call_date": 0,
        "description": "Crypto-Rewards Program http://ltbcoin.com",
        "owner": "1Hso4cqKAyx9bsan8b5nbPqMTNNce8ZDto",
        "call_price": 0,
        "divisible": true,
        "supply": 17731189327990000,
        "locked": false,
        "issuer": "1Hso4cqKAyx9bsan8b5nbPqMTNNce8ZDto"
    }
EOT
, true);
    }
}
