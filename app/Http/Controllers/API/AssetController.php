<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

	public function getMultiple(Cache $asset_info_cache, Request $request) {
		$errors = [];

		$assets = collect(explode(',', $request->input('assets')))
			->map('trim')
			->reject(function($asset) { return empty($asset); })
			->each(function($asset) use (&$errors) {
				if (!$this->isValidAssetName($asset)) {
					$errors[] = "The asset $asset is invalid";
				}
			})->toArray();

		if ($errors) {
			return new JsonResponse(['message' => 'Some asset names were invalid.', 'errors' => $errors], 400);
		}

		$asset_infos = $asset_info_cache->getMultiple($assets);
		return json_encode($asset_infos);

	}

	// ------------------------------------------------------------------------
	
	protected function isValidAssetName($name) {
	    if ($name === 'BTC') { return true; }
	    if ($name === 'XCP') { return true; }

	    // check free asset names
	    if (substr($name, 0, 1) == 'A') { return $this->isValidFreeAssetName($name); }

	    if (!preg_match('!^[A-Z]+$!', $name)) { return false; }
	    if (strlen($name) < 4) { return false; }

	    return true;
	}

	// allow integers between 26^12 + 1 and 256^8 (inclusive), prefixed with 'A'
	protected function isValidFreeAssetName($name) {
	    if (substr($name, 0, 1) != 'A') { return false; }

	    $number_string = substr($name, 1);
	    if (!preg_match('!^\\d+$!', $number_string)) { return false; }
	    if (bccomp($number_string, "95428956661682201") < 0) { return false; }
	    if (bccomp($number_string, "18446744073709600000") > 0) { return false; }

	    return true;
	}


}
