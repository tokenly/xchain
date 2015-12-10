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
            'ts'   => time(),
            'txid' => $raw_tx['txid'],
        ];
    }

    public function getSampleCounterpartyTransaction() {
        // 533.83451959 LTBCOIN
        return json_decode('{"txid":"89b0faa306c6b214329a13d6067d9b032a26b3990aefb23850d553a8c8216a18","version":1,"locktime":0,"vin":[{"txid":"d3cffc618712f5e45484508a64af0441abf04fb565fec375782d927e1db31726","vout":2,"scriptSig":{"asm":"3045022100d2e5b432140028f944341ec31b8b6050c97f12a73c245bf03c2d7d3389bffd5502202c60367de45daf3d90655c8ada94c7d1cf3c6d2ac7b4f6931a699c476b60e86101 0357be593e7d4c09a8e97a8c97cc759ddcec73e0ceb47cf31087a2c2727783d3d3","hex":"483045022100d2e5b432140028f944341ec31b8b6050c97f12a73c245bf03c2d7d3389bffd5502202c60367de45daf3d90655c8ada94c7d1cf3c6d2ac7b4f6931a699c476b60e86101210357be593e7d4c09a8e97a8c97cc759ddcec73e0ceb47cf31087a2c2727783d3d3"},"sequence":4294967295,"n":0,"addr":"13JhS7J6asCgw3utkp9Uap2tvttLG1obnB","valueSat":5447292,"value":0.05447292,"doubleSpentTxID":null}],"vout":[{"value":"0.00001250","n":0,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 c56cb39f9b289c0ec4ef6943fa107c904820fe09 OP_EQUALVERIFY OP_CHECKSIG","hex":"76a914c56cb39f9b289c0ec4ef6943fa107c904820fe0988ac","reqSigs":1,"type":"pubkeyhash","addresses":["1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD"]},"spentTxId":"f2f30d43d4aa5444c95dbeb06c9fc1de763ae496215cf83cf0cd9a6d25c6a083","spentIndex":4,"spentTs":1421077779},{"value":"0.00001250","n":1,"scriptPubKey":{"asm":"1 038cfb0cc0808f8fdb15e33407c195ca7885e40347486223b16908935dd5051732 03f0d0f3d0dd2159d795de1738192dd17bea77f830d3df177c95b5c1562575040b 0357be593e7d4c09a8e97a8c97cc759ddcec73e0ceb47cf31087a2c2727783d3d3 3 OP_CHECKMULTISIG","hex":"5121038cfb0cc0808f8fdb15e33407c195ca7885e40347486223b16908935dd50517322103f0d0f3d0dd2159d795de1738192dd17bea77f830d3df177c95b5c1562575040b210357be593e7d4c09a8e97a8c97cc759ddcec73e0ceb47cf31087a2c2727783d3d353ae","reqSigs":1,"type":"multisig","addresses":["1KGDh1ZiYeU2m3dnTuEndU4vrW2emrALyq","182k1B1WWHP6V7siXBGF3R5TymPspgNBGM","13JhS7J6asCgw3utkp9Uap2tvttLG1obnB"]}},{"value":"0.05441286","n":2,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 1949131981468beab8c05247b8726b98f0cbfd61 OP_EQUALVERIFY OP_CHECKSIG","hex":"76a9141949131981468beab8c05247b8726b98f0cbfd6188ac","reqSigs":1,"type":"pubkeyhash","addresses":["13JhS7J6asCgw3utkp9Uap2tvttLG1obnB"]},"spentTxId":"dabb4a086ca781114c333d579caaac7c46f11b8a0dd2aaa714f1434a62691d48","spentIndex":0,"spentTs":1420932311}],"blockhash":"000000000000000003f142b57c9d165be0da2439a324e30e2f4628380fb7966e","confirmations":746,"time":1420932311,"blocktime":1420932311,"valueOut":0.05443786,"size":340,"valueIn":0.05447292,"fees":0.00003506}', true);
    }

    public function getSampleBitcoinTransaction() {
        return json_decode('{"txid":"cf9d9f4d53d36d9d34f656a6d40bc9dc739178e6ace01bcc42b4b9ea2cbf6741","version":1,"locktime":0,"vin":[{"txid":"cc669b824186886407ad7edd46796437e20ad73c89080420c45e5803f917228d","vout":2,"scriptSig":{"asm":"3045022100a37bcfd3087fa4ba9480ce09c7adf02ba3ce2208d6170b42e50b5b2633b91ee6022025d409d3d9dae0a159982c7ab079787948b6b6c5f87fa583d3886ebf1e074c8901 02f4aef682535628a7e0492b2b5db1aa312348c3095e0258e26b275b25b10290e6"},"sequence":4294967295,"n":0,"addr":"1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1","valueSat":781213,"value":0.00781213,"doubleSpentTxID":null}],"vout":[{"value":"0.00400000","n":0,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 c56cb39f9b289c0ec4ef6943fa107c904820fe09 OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD"]},"spentTxId":"e90bc279294d704d09b227ad0e37459f61cccb85008605656dc8b024235eefe8","spentIndex":2,"spentTs":1403958484},{"value":"0.00361213","n":1,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 6ca4b6b20eac497e9ca94489c545a3372bdd2fa7 OP_EQUALVERIFY OP_CHECKSIG","reqSigs":1,"type":"pubkeyhash","addresses":["1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1"]},"spentTxId":"3587bfa8d96c10b6696728651900db2ad6b41321ea44f26693de4f90d2b63526","spentIndex":0,"spentTs":1405081243}],"blockhash":"00000000000000003a1e5abc2d7af7f38a614d2fcbafe309b7e8aa147d508a9c","confirmations":22369,"time":1403957896,"blocktime":1403957896,"valueOut":0.00761213,"size":226,"valueIn":0.00781213,"fees":0.0002}', true);
    }

    public function getSampleTopFolderCounterpartyTransaction() {
        return json_decode('{"txid":"c48c9a0367c84bc2ee900c793922945947b18189873ff50fb6db003c72ca4f10","version":1,"locktime":0,"vin":[{"txid":"abe9196366e0876570dc9054660f401cfd8ccfb6aa7be1375f30ee0416afc3de","vout":3,"scriptSig":{"asm":"3045022100c85fe2c886495ff33461929342fd05898f2a3b54f698c2bb2e9012fd9d62925d022075c33088a8cf5b61fb62b3787a6b5bc2a1da6399aa17762884373e6fb59fab5e01 03f4d43bb7382edf9c7985024552d69ba3190540fe4ed481b62aec78f657b3c60b","hex":"483045022100c85fe2c886495ff33461929342fd05898f2a3b54f698c2bb2e9012fd9d62925d022075c33088a8cf5b61fb62b3787a6b5bc2a1da6399aa17762884373e6fb59fab5e012103f4d43bb7382edf9c7985024552d69ba3190540fe4ed481b62aec78f657b3c60b"},"sequence":4294967295,"n":0,"addr":"1NVddDzRUvn8bHZEG9n5W7gfMTLeBeNAHQ","valueSat":2483130,"value":0.0248313,"doubleSpentTxID":null}],"vout":[{"value":"0.00005430","n":0,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 3d82664b4ea9b1f3fe0298d2b5a4cccfd2f3403b OP_EQUALVERIFY OP_CHECKSIG","hex":"76a9143d82664b4ea9b1f3fe0298d2b5a4cccfd2f3403b88ac","reqSigs":1,"type":"pubkeyhash","addresses":["16cES2Nxv9D5vjsMT5A4HEwhbUPDg3Nnpd"]}},{"value":"0.00007800","n":1,"scriptPubKey":{"asm":"1 0376b9c7de1106e3e254fe510c125503ae52cb6ef48fd559dfd052c59e7dfcf49d 03eae108e3bb3c9d03ce008c6e59eb7d6b18e64175d94a80ffe16d7abae99df85d 03f4d43bb7382edf9c7985024552d69ba3190540fe4ed481b62aec78f657b3c60b 3 OP_CHECKMULTISIG","hex":"51210376b9c7de1106e3e254fe510c125503ae52cb6ef48fd559dfd052c59e7dfcf49d2103eae108e3bb3c9d03ce008c6e59eb7d6b18e64175d94a80ffe16d7abae99df85d2103f4d43bb7382edf9c7985024552d69ba3190540fe4ed481b62aec78f657b3c60b53ae","reqSigs":1,"type":"multisig","addresses":["1GqjWoQm7u5AStmk9mmXkLGHeddcXagzm7","1Kc3ZJy7AatAGmxgxazgSbFj3GSVTJzUyu","1NVddDzRUvn8bHZEG9n5W7gfMTLeBeNAHQ"]}},{"value":"0.02459900","n":2,"scriptPubKey":{"asm":"OP_DUP OP_HASH160 ebc4dc6c23fd56d55ba2b917ddcdf2c94a404b13 OP_EQUALVERIFY OP_CHECKSIG","hex":"76a914ebc4dc6c23fd56d55ba2b917ddcdf2c94a404b1388ac","reqSigs":1,"type":"pubkeyhash","addresses":["1NVddDzRUvn8bHZEG9n5W7gfMTLeBeNAHQ"]},"spentTxId":"6fe7d28c636103ff0af899558caa56a414af6d84917e695abe7d5da0e3a92006","spentIndex":0,"spentTs":1420917955}],"blockhash":"0000000000000000073776af9fe180273a79ff95bdd6aa7e0c8989532295a3fe","confirmations":769,"time":1420917955,"blocktime":1420917955,"valueOut":0.0247313,"size":340,"valueIn":0.0248313,"fees":0.0001}', true);
    }

    public function sampleTopFolderAssetInfo() {
        return json_decode($_j=<<<EOT
    {
        "asset": "TOPFOLDER",
        "callable": false,
        "call_date": 0,
        "description": "Top monthly FLDC folders",
        "owner": "16cES2Nxv9D5vjsMT5A4HEwhbUPDg3Nnpd",
        "call_price": 0,
        "divisible": false,
        "supply": 100,
        "locked": false,
        "issuer": "1NVddDzRUvn8bHZEG9n5W7gfMTLeBeNAHQ"
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
