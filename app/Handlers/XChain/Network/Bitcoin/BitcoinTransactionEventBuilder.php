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

}
