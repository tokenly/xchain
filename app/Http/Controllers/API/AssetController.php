<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tokenly\AssetNameUtils\Validator as AssetValidator;
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

	public function getMultiple(Cache $asset_info_cache, Request $request) {
		$errors = [];

		$assets = collect(explode(',', $request->input('assets')))
			->map(function($asset) { return trim($asset); })
			->reject(function($asset) { return empty($asset); })
			->each(function($asset) use (&$errors) {
				if (!AssetValidator::isValidAssetName($asset)) {
					$errors[] = "The asset $asset is invalid";
				}
			})->toArray();

		if ($errors) {
			EventLog::logError('invalidAssetName', ['assets' => $assets, 'errors' => $errors]);
			return new JsonResponse(['message' => 'Some asset names were invalid.', 'errors' => $errors], 400);
		}

		$asset_infos = $asset_info_cache->getMultiple($assets);
		return json_encode($asset_infos);

	}

	// ------------------------------------------------------------------------
	


}
