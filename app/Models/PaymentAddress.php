<?php

namespace App\Models;

use App\Models\Base\APIModel;
use Tokenly\CopayClient\CopayWallet;
use \Exception;

/*
* PaymentAddress
*/
class PaymentAddress extends APIModel
{

    const TYPE_P2PKH = 1;
    const TYPE_P2SH  = 2;

    const COPAY_STATUS_PENDING  = 1;
    const COPAY_STATUS_COMPLETE = 2;

    protected $casts = [
        'copay_data' => 'json',
    ];

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'payment_address';

    protected static $unguarded = true;

    protected $api_attributes = ['id', 'address', 'type', 'status',];


    public function isManaged() {
        return (strlen($this['private_key_token']) > 0);
    }

    public function isMultisig() {
        return ($this['address_type'] == self::TYPE_P2SH);
    }

    public function isArchived() {
        return false;
    }

    public function getTypeAttribute() {
        switch ($this['address_type']) {
            case self::TYPE_P2PKH:
                return 'p2pkh';
            
            case self::TYPE_P2SH:
                return 'p2sh';
        }
    }

    public function getStatusAttribute() {
        if ($this->isMultisig()) {
            switch ($this['copay_status']) {
                case self::COPAY_STATUS_PENDING:  return 'pending';
                case self::COPAY_STATUS_COMPLETE: return 'complete';
            }
        }

        return 'complete';
    }

    public function getCopayWallet() {
        return CopayWallet::newWalletFromPlatformSeedAndWalletSeed(env('BITCOIN_MASTER_KEY'), $this['private_key_token']);
    }

    public function getCopayClient(CopayWallet $wallet) {
        $copay_client = app('Tokenly\CopayClient\CopayClient');

        $copay_client->withSharedEncryptionService(
            app('Tokenly\CopayClient\EncryptionService\EncryptionServiceClient')->withEncryptionKey($wallet['sharedEncryptingKey'])
        );
        $copay_client->withPersonalEncryptionService(
            app('Tokenly\CopayClient\EncryptionService\EncryptionServiceClient')->withEncryptionKey($wallet['personalEncryptingKey'])
        );

        return $copay_client;
    }

}
