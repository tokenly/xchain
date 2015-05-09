<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;

class AssetController extends APIController {
	
	public function get(Cache $asset_info_cache, $asset)
	{
		$asset = $asset_info_cache->get(strtoupper($asset));
		if(!$asset){
            $message = "The asset $asset could not be found";
            EventLog::logError('error.getAsset', ['asset' => $asset, 'message' => $message]);
            return new JsonResponse(['message' => $message], 500); 
		}
		return json_encode($asset);
	}
}
