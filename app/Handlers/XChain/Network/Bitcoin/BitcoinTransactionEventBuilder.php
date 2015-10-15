<?php

namespace App\Handlers\XChain\Network\Bitcoin;

use App\Blockchain\Block\BlockChainStore;
use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CounterpartyTransactionParser\Parser;
use Tokenly\CurrencyLib\CurrencyUtil;
use \Exception;

/*
* BitcoinTransactionEventBuilder
*/
class BitcoinTransactionEventBuilder
{
    public function __construct(Parser $parser, BlockChainStore $blockchain_store, Cache $asset_cache)
    {
        $this->parser           = $parser;
        $this->blockchain_store = $blockchain_store;
        $this->asset_cache      = $asset_cache;
    }

    public function buildParsedTransactionData($xstalker_data)
    {
        try {
            $parsed_transaction_data = [];
            $parsed_transaction_data = [
                'txid'               => $xstalker_data['tx']['txid'],
                'network'            => 'bitcoin',
                'timestamp'          => round($xstalker_data['ts'] / 1000),

                'counterPartyTxType' => false,

                'sources'            => [],
                'destinations'       => [],
                'values'             => [],
                'asset'              => false,

                'bitcoinTx'          => $xstalker_data['tx'],
                'counterpartyTx'     => [],
            ];

            $xcp_data = $this->parser->parseBitcoinTransaction($xstalker_data['tx']);
            if ($xcp_data) {
                // ensure 1 source and 1 desination
                if (!isset($xcp_data['sources'][0])) {
                    $xcp_data['sources'] = ['unknown'];
                    Log::error("No source found in transaction: ".((isset($xstalker_data['tx']) AND isset($xstalker_data['tx']['txid'])) ? $xstalker_data['tx']['txid'] : "unknown"));
                }
                if (!isset($xcp_data['destinations'][0])) {
                    $xcp_data['destinations'] = ['unknown'];
                    Log::error("No destination found in transaction: ".((isset($xstalker_data['tx']) AND isset($xstalker_data['tx']['txid'])) ? $xstalker_data['tx']['txid'] : "unknown"));
                }

                // this is a counterparty transaction
                $parsed_transaction_data['network']            = 'counterparty';
                $parsed_transaction_data['counterPartyTxType'] = $xcp_data['type'];

                $parsed_transaction_data['sources']            = $xcp_data['sources'];
                $parsed_transaction_data['destinations']       = $xcp_data['destinations'];

                if ($xcp_data['type'] === 'send') {
                    $is_divisible = $this->asset_cache->isDivisible($xcp_data['asset']);

                    // if the asset info doesn't exist, assume it is divisible
                    if ($is_divisible === null) { $is_divisible = true; }

                    if ($is_divisible) {
                        $quantity_sat   = $xcp_data['quantity'];
                        $quantity_float = CurrencyUtil::satoshisToValue($xcp_data['quantity']);
                    } else {
                        $quantity_sat   = CurrencyUtil::valueToSatoshis($xcp_data['quantity']);
                        $quantity_float = intval($xcp_data['quantity']);
                    }
                    $xcp_data['quantity']    = $quantity_float;
                    $xcp_data['quantitySat'] = $quantity_sat;

                    $destination = $xcp_data['destinations'][0];
                    $parsed_transaction_data['values']     = [$destination => $quantity_float];
                    $parsed_transaction_data['asset']      = $xcp_data['asset'];

                    // dustSize
                    // dustSizeSat
                    list($sources, $quantity_by_destination) = $this->extractSourcesAndDestinations($xstalker_data['tx']);
                    $dust_size_float = (isset($quantity_by_destination[$destination]) ? $quantity_by_destination[$destination] : 0);

                    $xcp_data['dustSize'] = $dust_size_float;
                    $xcp_data['dustSizeSat'] = CurrencyUtil::valueToSatoshis($dust_size_float);
                }

                // Log::debug("\$xcp_data=".json_encode($xcp_data, 192));
                $parsed_transaction_data['counterpartyTx'] = $xcp_data;



            } else  {
                // this is just a bitcoin transaction
                list($sources, $quantity_by_destination) = $this->extractSourcesAndDestinations($xstalker_data['tx']);

                $parsed_transaction_data['network']      = 'bitcoin';
                $parsed_transaction_data['sources']      = $sources;
                $parsed_transaction_data['destinations'] = array_keys($quantity_by_destination);
                $parsed_transaction_data['values']       = $quantity_by_destination;
                $parsed_transaction_data['asset']        = 'BTC';
            }

            // add a blockheight
            if (isset($parsed_transaction_data['bitcoinTx']['blockhash']) AND $hash = $parsed_transaction_data['bitcoinTx']['blockhash']) {
                $block = $this->blockchain_store->findByHash($hash);
                $parsed_transaction_data['bitcoinTx']['blockheight'] = $block ? $block['height'] : null;
            }

            // add a transaction fingerprint
            $parsed_transaction_data['transactionFingerprint'] = $this->buildFingerprint($parsed_transaction_data['bitcoinTx']);


            return $parsed_transaction_data;

        } catch (Exception $e) {
            Log::warning("Failed to parse transaction: ".((isset($xstalker_data['tx']) AND isset($xstalker_data['tx']['txid'])) ? $xstalker_data['tx']['txid'] : "unknown")."\n".$e->getMessage());
            // print "ERROR: ".$e->getMessage()."\n";
            // echo "\$parsed_transaction_data:\n".json_encode($parsed_transaction_data, 192)."\n";
            throw $e;
        }

    }


    protected function extractSourcesAndDestinations($tx) {
        $sources_map = [];
        foreach ($tx['vin'] as $vin) {
            if (isset($vin['addr'])) {
                $sources_map[$vin['addr']] = true;
            }
        }

        $quantity_by_destination = [];
        foreach ($tx['vout'] as $vout) {
            if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['addresses'])) {
                if ($vout['scriptPubKey']['type'] == 'pubkeyhash') {
                    foreach($vout['scriptPubKey']['addresses'] as $destination_address) {
                        // ignore change
                        if (isset($sources_map[$destination_address])) { continue; }

                        $quantity_by_destination[$destination_address] = (isset($quantity_by_destination[$destination_address]) ? $quantity_by_destination[$destination_address] : 0) + $vout['value'];
                    }
                }
            }
        }

        return [array_keys($sources_map), $quantity_by_destination];
    }

    protected function buildFingerprint($bitcoin_tx) {

        // "vin": [
        //     {
        //         "addr": "1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1",
        //         "doubleSpentTxID": null,
        //         "n": 0,
        //         "scriptSig": {
        //             "asm": "3045022100a37bcfd3087fa4ba9480ce09c7adf02ba3ce2208d6170b42e50b5b2633b91ee6022025d409d3d9dae0a159982c7ab079787948b6b6c5f87fa583d3886ebf1e074c8901 02f4aef682535628a7e0492b2b5db1aa312348c3095e0258e26b275b25b10290e6"
        //         },
        //         "sequence": 4294967295,
        //         "txid": "cc669b824186886407ad7edd46796437e20ad73c89080420c45e5803f917228d",
        //         "value": 0.00781213,
        //         "valueSat": 781213,
        //         "vout": 2
        //     }
        // ],
        // "vout": [
        //     {
        //         "n": 0,
        //         "scriptPubKey": {
        //             "addresses": [
        //                 "1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD"
        //             ],
        //             "asm": "OP_DUP OP_HASH160 c56cb39f9b289c0ec4ef6943fa107c904820fe09 OP_EQUALVERIFY OP_CHECKSIG",
        //             "reqSigs": 1,
        //             "type": "pubkeyhash"
        //         },
        //         "spentIndex": 2,
        //         "spentTs": 1403958484,
        //         "spentTxId": "e90bc279294d704d09b227ad0e37459f61cccb85008605656dc8b024235eefe8",
        //         "value": "0.00400000"
        //     },
        //     {
        //         "n": 1,
        //         "scriptPubKey": {
        //             "addresses": [
        //                 "1AuTJDwH6xNqxRLEjPB7m86dgmerYVQ5G1"
        //             ],
        //             "asm": "OP_DUP OP_HASH160 6ca4b6b20eac497e9ca94489c545a3372bdd2fa7 OP_EQUALVERIFY OP_CHECKSIG",
        //             "reqSigs": 1,
        //             "type": "pubkeyhash"
        //         },
        //         "spentIndex": 0,
        //         "spentTs": 1405081243,
        //         "spentTxId": "3587bfa8d96c10b6696728651900db2ad6b41321ea44f26693de4f90d2b63526",
        //         "value": "0.00361213"
        //     }


        $scripts = [];
        foreach($bitcoin_tx['vin'] as $vin){
            if (isset($vin['scriptSig']) AND isset($vin['scriptSig']['asm'])) {
                $scripts[] = $vin['scriptSig']['asm'];
            } else if (isset($vin['coinbase'])) {
                $scripts[] = $vin['coinbase'];
            } else {
                Log::warning("WARNING: no scriptSig for tx {$bitcoin_tx['txid']}".json_encode($bitcoin_tx, 192));
                $scripts[] = json_encode($vin);
            }
        }
        foreach($bitcoin_tx['vout'] as $vout){
            if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['asm'])) {
                $scripts[] = $vout['scriptPubKey']['asm'];
            } else {
                Log::warning("WARNING: no scriptPubKey for tx {$bitcoin_tx['txid']}".json_encode($bitcoin_tx, 192));
                $scripts[] = json_encode($vout);
            }
        }

        return hash('sha256', implode('|', $scripts));
    }

}
