<?php

namespace App\Models;

use App\Models\Base\APIModel;
use \Exception;

/*
* Transaction
*/
class Transaction extends APIModel
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'transaction';

    protected static $unguarded = true;

    public function setParsedTxAttribute($parsed_tx) { $this->attributes['parsed_tx'] = json_encode($parsed_tx); }
    public function getParsedTxAttribute() { return json_decode($this->attributes['parsed_tx'], true); }

    public function setNetworkAttribute($network_string) { $this->attributes['network'] = $this->networkStringToNetworkID($network_string); }
    public function getNetworkAttribute() { return $this->networkIDToNetworkString($this->attributes['network']); }


    protected function networkStringToNetworkID($network_string) {
        switch ($network_string) {
            case 'bitcoin': return 1;
            case 'counterparty': return 2;
        }
        throw new Exception("unknown network String: $network_string", 1);
    }

    protected function networkIDToNetworkString($network_id) {
        switch ($network_id) {
            case 1: return 'bitcoin';
            case 2: return 'counterparty';
        }
        throw new Exception("unknown network ID: $network_id", 1);
        
    }

}
