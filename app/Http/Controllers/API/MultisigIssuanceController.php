<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\Exception\BitcoinDaemonException;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Send\ComposeMultisigIssuanceRequest;
use App\Repositories\APICallRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Exception;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tokenly\CopayClient\CopayClient;
use Tokenly\CopayClient\CopayWallet;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Tokenly\CryptoQuantity\CryptoQuantity;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\RecordLock\Facade\RecordLock;

class MultisigIssuanceController extends APIController {

    const COMPOSE_LOCK_TIME = 30;

    /**
     * Create and execute a issuance
     *
     * @return Response
     */
    public function proposeSignAndPublishIssuance(APIControllerHelper $helper, ComposeMultisigIssuanceRequest $request, SendRepository $send_repository, PaymentAddressRepository $payment_address_repository, Cache $asset_cache, CopayClient $copay_client, $address_uuid) {
        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        // get the address owned by the current user
        $user = Auth::getUser();
        if (!$user) { throw new Exception("User not found", 1); }
        $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

        // compose the issuance
        try {
            $acquired_lock = false;
            $record_lock_key = $this->buildRecordLockKey($payment_address);
            $wait_time = (app()->environment() == 'testing' ? 1 : self::COMPOSE_LOCK_TIME);
            $acquired_lock = RecordLock::acquireOnce($record_lock_key, $wait_time);
            if (!$acquired_lock) { throw new HttpResponseException(new JsonResponse(['message' => 'Unable to compose a new transaction for this address.'], 500)); }

            $is_divisible = !!$request_attributes['divisible'];
            $quantity = CryptoQuantity::fromFloat($request_attributes['quantity'], $is_divisible);

            // compose the issuance
            $request_id = (isset($request_attributes['requestId']) AND strlen($request_attributes['requestId'])) ? $request_attributes['requestId'] : Uuid::uuid4()->toString();

            // create attibutes
            $create_attributes = [];
            $create_attributes['user_id']            = $user['id'];
            $create_attributes['payment_address_id'] = $payment_address['id'];
            $create_attributes['quantity_sat']       = $quantity->getValueForCounterparty();
            $create_attributes['asset']              = $request_attributes['asset'];
            $create_attributes['destination']        = '';
            $create_attributes['transaction_type']   = 'issuance';
            $description = $request_attributes['description'];

            list($send_model, $transaction_proposal) = $send_repository->executeWithNewLockedSendByRequestID($request_id, $create_attributes, 
                function($locked_send) use ($copay_client, $request_attributes, $payment_address, $is_divisible, $description, $send_repository) {
                    $wallet = $payment_address->getCopayWallet();
                    $copay_client = $payment_address->getCopayClient($wallet);

                    $args = [
                        'counterpartyType' => 'issuance',
                        'amountSat'        => $locked_send['quantity_sat'],
                        'token'            => $locked_send['asset'],
                        'divisible'        => $is_divisible,
                        'description'      => $description,
                        'feePerKBSat'      => CurrencyUtil::valueToSatoshis($request_attributes['feePerKB']),
                    ];

                    // no message for issuances
                    // if (isset($request_attributes['message'])) {
                    //     $args['message'] = $request_attributes['message'];
                    // }

                    $transaction_proposal = $copay_client->proposePublishAndSignTransaction($wallet, $args);
                    $send_repository->update($locked_send, [
                        'tx_proposal_id' => $transaction_proposal['id'],
                    ]);

                    return [$locked_send, $transaction_proposal];
                }
            );

            // release the lock
            RecordLock::release($record_lock_key);
        } catch (Exception $e) {
            EventLog::logError('composeMultisigSend.error', $e, $request_attributes);

            if ($acquired_lock) { RecordLock::release($record_lock_key); }

            throw $e;
        }

        $api_data = $send_model->serializeForAPI('multisig');

        // also include the transaction proposal details
        $api_data['copayTransaction'] = $transaction_proposal;

        return $helper->buildJSONResponse($api_data);
    }

    // ------------------------------------------------------------------------
    
    protected function buildRecordLockKey($payment_address) {
        return 'compose-issuance/'.$payment_address['uuid'];
    }    
}
