<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\LaravelEventLog\Facade\EventLog;

class AssetController extends APIController {
	
	/**
	 * gets info for a counterparty asset
	 * @param string $asset
	 * @return Response
	 * */
	public function get(Cache $asset_info_cache, $asset)
	{
		$asset_info = $asset_info_cache->get(strtoupper($asset));
		if (!$asset_info) {
            $message = "The asset $asset was not found";
            EventLog::logError('error.getAsset', ['asset' => $asset, 'message' => $message]);
            return new JsonResponse(['message' => $message], 404); 
		}
		return json_encode($asset_info);
	}
}
