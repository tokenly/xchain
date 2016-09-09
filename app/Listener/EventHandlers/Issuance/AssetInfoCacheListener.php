<?php

namespace App\Listener\EventHandlers\Issuance;

use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;

class AssetInfoCacheListener {

    function __construct(Cache $asset_info_cache) {
        $this->asset_info_cache = $asset_info_cache;
    }

    public function handle($counterparty_data, $confirmations, $full_parsed_tx) {

        // for confirmed issuances, clear the asset info cache
        //   and reload it
        if ($confirmations > 0) {
            Log::debug("clearing asset info cache for {$counterparty_data['type']} of {$counterparty_data['asset']}".json_encode($counterparty_data, 192));
            $this->asset_info_cache->forget($counterparty_data['asset']);
        }
    }

    public function subscribe($events) {
        $events->listen('xchain.counterpartyTx.issuance',  'App\Listener\EventHandlers\Issuance\AssetInfoCacheListener@handle');
    }

}
