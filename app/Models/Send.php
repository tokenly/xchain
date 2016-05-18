<?php

namespace App\Models;

use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Model\APIModel;
use \Exception;

/*
* Send
*/
class Send extends APIModel
{


    protected $casts = [
        'destinations' => 'json',
        'utxos'        => 'json',
        'unsigned'     => 'boolean',
    ];

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'send';

    protected static $unguarded = true;

    protected $api_attributes = ['id', 'destination', 'destinations', 'quantity', 'asset', 'sweep', 'txid', 'request_id', ];
    protected $api_attributes_composed = ['id', 'destination', 'destinations', 'quantity', 'asset', 'sweep', 'txid', 'request_id', 'unsigned_tx', 'utxos', ];

    public function setSendDataAttribute($send_data) { $this->attributes['send_data'] = json_encode($send_data); }
    public function getSendDataAttribute() { return json_decode($this->attributes['send_data'], true); }

    public function setSweepAttribute($sweep) { $this->attributes['is_sweep'] = ($sweep ? 1 : 0); }
    public function getSweepAttribute() { return !!$this->attributes['is_sweep']; }

    public function setQuantityAttribute($quantity) { $this->attributes['quantity_sat'] = CurrencyUtil::valueToSatoshis($quantity); }
    public function getQuantityAttribute() { return CurrencyUtil::satoshisToValue($this->attributes['quantity_sat']); }

    public function setFeeAttribute($fee) { $this->attributes['fee_sat'] = CurrencyUtil::valueToSatoshis($fee); }
    public function getFeeAttribute() { return CurrencyUtil::satoshisToValue($this->attributes['fee_sat']); }

    public function setMultisigDustSizeAttribute($multisig_dust_size) { $this->attributes['multisig_dust_size_sat'] = CurrencyUtil::valueToSatoshis($multisig_dust_size); }
    public function getMultisigDustSizeAttribute() { return CurrencyUtil::satoshisToValue($this->attributes['multisig_dust_size_sat']); }

    public function setDustSizeAttribute($dust_size) { $this->attributes['dust_size_sat'] = CurrencyUtil::valueToSatoshis($dust_size); }
    public function getDustSizeAttribute() { return CurrencyUtil::satoshisToValue($this->attributes['dust_size_sat']); }



}
