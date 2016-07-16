<?php

namespace App\Blockchain\Composer;

use Exception;

class ComposerUtil {

    public static function buildAssetQuantities($float_quantity, $asset, $float_fee=0, $dust_size=0) {
        $assets_to_send = ['BTC' => 0];
        $assets_to_send[$asset] = $float_quantity;
        $assets_to_send['BTC'] += $float_fee;
        if ($asset != 'BTC') {
            $assets_to_send['BTC'] += $dust_size;
        }
        return $assets_to_send;
    }
}
