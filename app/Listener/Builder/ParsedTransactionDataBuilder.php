<?php

namespace App\Listener\Builder;

use App\Blockchain\Block\BlockChainStore;
use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CounterpartyTransactionParser\Parser;
use Tokenly\CurrencyLib\CurrencyUtil;
use \Exception;

/*
* ParsedTransactionDataBuilder
*/
class ParsedTransactionDataBuilder
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
                'timestamp'          => round($xstalker_data['ts'] / 1000),

                'isCounterpartyTx'   => false,
                'counterPartyTxType' => false,

                'sources'            => [],
                'destinations'       => [],
                'values'             => [],
                // 'quantity'           => 0.0,
                // 'quantitySat'        => 0,
                'asset'              => false,

                'bitcoinTx'          => $xstalker_data['tx'],
                'counterpartyTx'     => [],
            ];

            $xcp_data = $this->parser->parseBitcoinTransaction($xstalker_data['tx']);
            if ($xcp_data) {
                // this is a counterparty transaction
                $parsed_transaction_data['isCounterpartyTx']   = true;
                $parsed_transaction_data['counterPartyTxType'] = $xcp_data['type'];

                $parsed_transaction_data['sources']            = $xcp_data['sources'];
                $parsed_transaction_data['destinations']       = $xcp_data['destinations'];

                if ($xcp_data['type'] === 'send') {
                    $is_divisible = $this->asset_cache->isDivisible($xcp_data['asset']);

                    // if the asset info doesn't exist, assume it is divisible
                    if ($is_divisible === null) { $is_divisible = true; }

                    if ($is_divisible) {

                        // $quantity_sat = intval($xcp_data['quantity']);
                        $quantity     = CurrencyUtil::satoshisToValue($xcp_data['quantity']);
                    } else {
                        // $quantity_sat = CurrencyUtil::valueToSatoshis($xcp_data['quantity']);
                        $quantity     = intval($xcp_data['quantity']);
                    }

                    $destination = $xcp_data['destinations'][0];
                    $parsed_transaction_data['values']     = [$destination => $quantity];
                    // $parsed_transaction_data['valuesSat']  = [$destination => $quantity_sat];
                    $parsed_transaction_data['asset']      = $xcp_data['asset'];
                }

                $parsed_transaction_data['counterpartyTx']     = $xcp_data;

            } else  {
                // this is just a bitcoin transaction
                list($sources, $quantity_by_destination) = $this->extractSourcesAndDestinations($xstalker_data['tx']);

                $parsed_transaction_data['isCounterpartyTx'] = false;
                $parsed_transaction_data['sources']      = $sources;
                $parsed_transaction_data['destinations'] = array_keys($quantity_by_destination);
                $parsed_transaction_data['values']        = $quantity_by_destination;
                // $parsed_transaction_data['valuesSat']     = $quantity_by_destination_sat;
                $parsed_transaction_data['asset']        = 'BTC';
            }

            // add a blockheight
            if (isset($parsed_transaction_data['bitcoinTx']['blockhash']) AND $hash = $parsed_transaction_data['bitcoinTx']['blockhash']) {
                $block = $this->blockchain_store->findByHash($hash);
                $parsed_transaction_data['bitcoinTx']['blockheight'] = $block ? $block['height'] : null;
            }

            return $parsed_transaction_data;

        } catch (Exception $e) {
            print "ERROR: ".$e->getMessage()."\n";
            echo "\$parsed_transaction_data:\n".json_encode($parsed_transaction_data, 192)."\n";
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

        // // build a version with satoshis
        // $quantity_by_destination_sat = [];
        // foreach($quantity_by_destination as $address => $value) {
        //     $quantity_by_destination_sat[$address] = CurrencyUtil::valueToSatoshis($value);
        // }

        // return [array_keys($sources_map), $quantity_by_destination, $quantity_by_destination_sat];
        return [array_keys($sources_map), $quantity_by_destination];
    }

}
