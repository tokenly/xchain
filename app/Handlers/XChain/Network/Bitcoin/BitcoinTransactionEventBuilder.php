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

    const XCP_ISSUANCE_FEE = 0.5;  // 0.5 XCP issuance fee

    public function __construct(Parser $parser, BlockChainStore $blockchain_store, Cache $asset_cache)
    {
        $this->parser           = $parser;
        $this->blockchain_store = $blockchain_store;
        $this->asset_cache      = $asset_cache;
    }

    public function buildParsedTransactionData($bitcoin_transaction_data, $ts)
    {
        try {
            $parsed_transaction_data = [];
            $parsed_transaction_data = [
                'txid'               => $bitcoin_transaction_data['txid'],
                'network'            => 'bitcoin',
                'timestamp'          => round($ts / 1000),

                'counterPartyTxType' => false,

                'sources'            => [],
                'destinations'       => [],
                'spentAssets'        => [],
                'receivedAssets'     => [],
                'values'             => [],
                'asset'              => false,

                'bitcoinTx'          => $bitcoin_transaction_data,
                'counterpartyTx'     => [],
            ];

            $xcp_data = $this->parser->parseBitcoinTransaction($bitcoin_transaction_data);
            if ($xcp_data) {
                // ensure 1 source and 1 desination
                if (!isset($xcp_data['sources'][0])) {
                    $xcp_data['sources'] = ['unknown'];
                    Log::error("No source found in transaction: ".((isset($bitcoin_transaction_data) AND isset($bitcoin_transaction_data['txid'])) ? $bitcoin_transaction_data['txid'] : "unknown"));
                }
                if (!isset($xcp_data['destinations'][0])) {
                    $xcp_data['destinations'] = ['unknown'];
                    Log::error("No destination found in transaction: ".((isset($bitcoin_transaction_data) AND isset($bitcoin_transaction_data['txid'])) ? $bitcoin_transaction_data['txid'] : "unknown"));
                }

                // this is a counterparty transaction
                $parsed_transaction_data['network']            = 'counterparty';
                $parsed_transaction_data['counterPartyTxType'] = $xcp_data['type'];

                $parsed_transaction_data['sources']            = $xcp_data['sources'];
                $parsed_transaction_data['destinations']       = $xcp_data['destinations'];

                if ($xcp_data['type'] === 'send') {
                    $parsed_transaction_data = $this->buildParsedTransactionData_send($bitcoin_transaction_data, $xcp_data, $parsed_transaction_data);
                } else if ($xcp_data['type'] === 'issuance') {
                    $parsed_transaction_data = $this->buildParsedTransactionData_issuance($bitcoin_transaction_data, $xcp_data, $parsed_transaction_data);
                } else {
                    // default
                    $parsed_transaction_data['counterpartyTx'] = $xcp_data;
                }




            } else  {
                // this is just a bitcoin transaction
                list($sources, $quantity_by_destination, $asset_quantities_by_source, $asset_quantities_by_destination) = $this->extractSourcesAndDestinations($bitcoin_transaction_data);

                $parsed_transaction_data['network']        = 'bitcoin';
                $parsed_transaction_data['sources']        = $sources;
                $parsed_transaction_data['destinations']   = array_keys($quantity_by_destination);
                $parsed_transaction_data['values']         = $quantity_by_destination;
                $parsed_transaction_data['spentAssets']    = $asset_quantities_by_source;
                $parsed_transaction_data['receivedAssets'] = $asset_quantities_by_destination;
                $parsed_transaction_data['asset']          = 'BTC';
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
            Log::warning("Failed to parse transaction: ".((isset($bitcoin_transaction_data) AND isset($bitcoin_transaction_data['txid'])) ? $bitcoin_transaction_data['txid'] : "unknown")."\n".$e->getMessage());
            // print "ERROR: ".$e->getMessage()."\n";
            // echo "\$parsed_transaction_data:\n".json_encode($parsed_transaction_data, 192)."\n";
            throw $e;
        }

    }

    protected function buildParsedTransactionData_send($bitcoin_transaction_data, $xcp_data, $parsed_transaction_data) {
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

        list($sources, $quantity_by_destination, $asset_quantities_by_source, $asset_quantities_by_destination) = $this->extractSourcesAndDestinations($bitcoin_transaction_data);

        // add the XCP assets to the spent and received assets

        // spent
        $parsed_transaction_data['spentAssets'] = $asset_quantities_by_source;
        $source_address = $xcp_data['sources'][0];
        if (!isset($parsed_transaction_data['spentAssets'][$source_address])) {
            $parsed_transaction_data['spentAssets'][$source_address] = [];
        }
        $parsed_transaction_data['spentAssets'][$source_address][$xcp_data['asset']] = $quantity_float;

        // received assets
        $parsed_transaction_data['receivedAssets'] = $asset_quantities_by_destination;
        if (!isset($parsed_transaction_data['receivedAssets'][$destination])) {
            $parsed_transaction_data['receivedAssets'][$destination] = [];
        }
        $parsed_transaction_data['receivedAssets'][$destination][$xcp_data['asset']] = $quantity_float;

        // dustSize
        // dustSizeSat
        $dust_size_float = (isset($quantity_by_destination[$destination]) ? $quantity_by_destination[$destination] : 0);

        $xcp_data['dustSize'] = $dust_size_float;
        $xcp_data['dustSizeSat'] = CurrencyUtil::valueToSatoshis($dust_size_float);

        $parsed_transaction_data['counterpartyTx'] = $xcp_data;

        return $parsed_transaction_data;
    }

    protected function buildParsedTransactionData_issuance($bitcoin_transaction_data, $xcp_data, $parsed_transaction_data) {
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

        // for an issuance, there is no source 
        //   and we assign the source to the destination
        $issuing_address = $xcp_data['sources'][0];
        $destination = $xcp_data['sources'][0];
        $parsed_transaction_data['values']     = [$destination => $quantity_float];
        $parsed_transaction_data['asset']      = $xcp_data['asset'];
        $xcp_data['sources'] = [];
        $xcp_data['destinations'] = [$destination];
        $parsed_transaction_data['sources'] = [];
        $parsed_transaction_data['destinations'] = [$destination];

        // get the sources and spent assets
        list($sources, $quantity_by_destination, $asset_quantities_by_source, $asset_quantities_by_destination) = $this->extractSourcesAndDestinations($bitcoin_transaction_data);

        // deduct the XCP fee for issuance
        $parsed_transaction_data['spentAssets'] = $asset_quantities_by_source;
        if (!isset($parsed_transaction_data['spentAssets'][$issuing_address])) {
            $parsed_transaction_data['spentAssets'][$issuing_address] = [];
        }
        if (substr($xcp_data['asset'], 0, 1) !== 'A') {
            $parsed_transaction_data['spentAssets'][$issuing_address]['XCP'] = self::XCP_ISSUANCE_FEE;
        }

        // received assets
        $parsed_transaction_data['receivedAssets'] = $asset_quantities_by_destination;
        if (!isset($parsed_transaction_data['receivedAssets'][$destination])) {
            $parsed_transaction_data['receivedAssets'][$destination] = [];
        }
        $parsed_transaction_data['receivedAssets'][$destination][$xcp_data['asset']] = $quantity_float;


        // dustSize
        $xcp_data['dustSize'] = 0;
        $xcp_data['dustSizeSat'] = 0;

        $parsed_transaction_data['counterpartyTx'] = $xcp_data;

        return $parsed_transaction_data;
    }

    protected function extractSourcesAndDestinations($tx) {
        $asset_quantities_by_source = [];
        foreach ($tx['vin'] as $vin) {
            if (isset($vin['addr'])) {
                if (!isset($asset_quantities_by_source[$vin['addr']])) {
                    $asset_quantities_by_source[$vin['addr']] = ['BTC' => 0];
                }
                $asset_quantities_by_source[$vin['addr']]['BTC'] += $vin['value'];
            }
        }

        $quantity_by_destination = [];
        $asset_quantities_by_destination = [];
        foreach ($tx['vout'] as $vout) {
            if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['addresses'])) {
                if ($vout['scriptPubKey']['type'] == 'pubkeyhash' OR $vout['scriptPubKey']['type'] == 'scripthash') {
                    foreach($vout['scriptPubKey']['addresses'] as $destination_address) {
                        // handle change
                        if (isset($asset_quantities_by_source[$destination_address])) {
                            // subtract the change from the quantity by source
                            $asset_quantities_by_source[$destination_address]['BTC'] -= $vout['value'];
                            continue;
                        }

                        if (!isset($quantity_by_destination[$destination_address])) {
                            $quantity_by_destination[$destination_address] = 0;
                            $asset_quantities_by_destination[$destination_address] = ['BTC' => 0];
                        }

                        $quantity_by_destination[$destination_address] += $vout['value'];
                        $asset_quantities_by_destination[$destination_address]['BTC'] += $vout['value'];
                    }
                }
            }
        }

        return [array_keys($asset_quantities_by_source), $quantity_by_destination, $asset_quantities_by_source, $asset_quantities_by_destination];
    }

    protected function buildFingerprint($bitcoin_tx) {
        $scripts = [];
        foreach($bitcoin_tx['vin'] as $vin_offset => $vin){
            if (isset($vin['txid']) AND isset($vin['vout'])) {
                $scripts[] = $vin['txid'].':'.$vin['vout'];
            } else if (isset($vin['coinbase'])) {
                $scripts[] = $vin['coinbase'];
            } else {
                Log::warning("WARNING: no txid or vout for vin {$vin_offset} in transaction {$bitcoin_tx['txid']}".json_encode($bitcoin_tx, 192));
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
