<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LinusU\Bitcoin\AddressValidator;
use Tokenly\BitcoinPayer\BitcoinPayer;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XCPDClient\Client;

class BalancesController extends APIController {


    /**
     * Get all balances for an address
     *
     * @param  int  $id
     * @return Response
     */
    public function show(Client $xcpd_client, BitcoinPayer $bitcoin_payer, Cache $asset_info_cache, $address)
    {
        if (!AddressValidator::isValid($address)) {
            $message = "The address $address was not valid";
            EventLog::logError('error.getBalance', ['address' => $address, 'message' => $message]);
            return new JsonResponse(['message' => $message], 500); 
        }

        $balances = $xcpd_client->get_balances(['filters' => ['field' => 'address', 'op' => '==', 'value' => $address]]);

        // and get BTC balance too
        $btc_float_balance = $bitcoin_payer->getBalance($address);
        $balances = array_merge([['asset' => 'BTC', 'quantity' => $btc_float_balance]], $balances);



        $out = ['balances' => [], 'balancesSat' => []];
        foreach($balances as $balance) {
            $asset_name = $balance['asset'];

            if ($asset_name == 'BTC') {
                // BTC quantity is a float
                $quantity_float = floatval($balance['quantity']);
                $quantity_sat = CurrencyUtil::valueToSatoshis($balance['quantity']);
            } else {
                // determine quantity based on asset info
                $is_divisible = $asset_info_cache->isDivisible($asset_name);
                if ($is_divisible) {
                    $quantity_float = CurrencyUtil::satoshisToValue($balance['quantity']);
                    $quantity_sat = intval($balance['quantity']);
                } else {
                    // non-divisible assets don't use satoshis
                    $quantity_float = floatval($balance['quantity']);
                    $quantity_sat = CurrencyUtil::valueToSatoshis($balance['quantity']);
                }
            }

            $out['balances'][$asset_name] = $quantity_float;
            $out['balancesSat'][$asset_name] = $quantity_sat;

        }

        ksort($out['balances']);
        ksort($out['balancesSat']);

        return json_encode($out);
    }

}
