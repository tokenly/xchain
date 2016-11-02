<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\Exception\BitcoinDaemonException;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Send\ComposeMultisigSendRequest;
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

class MultisigSendController extends APIController {

    const COMPOSE_LOCK_TIME = 30;

    /**
     * Create and execute a send
     *
     * @return Response
     */
    public function publishSignedSend(APIControllerHelper $helper, ComposeMultisigSendRequest $request, SendRepository $send_respository, PaymentAddressRepository $payment_address_repository, Cache $asset_cache, CopayClient $copay_client, $address_uuid) {
        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        // get the address owned by the current user
        $user = Auth::getUser();
        if (!$user) { throw new Exception("User not found", 1); }
        $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

        // compose the send
        try {
            $acquired_lock = false;
            $record_lock_key = $this->buildRecordLockKey($payment_address);
            $wait_time = (app()->environment() == 'testing' ? 1 : self::COMPOSE_LOCK_TIME);
            $acquired_lock = RecordLock::acquireOnce($record_lock_key, $wait_time);
            if (!$acquired_lock) { throw new HttpResponseException(new JsonResponse(['message' => 'Unable to compose a new transaction for this address.'], 500)); }

            $is_divisible = $request_attributes['asset'] == 'BTC' ? true : $asset_cache->isDivisible($request_attributes['asset']);
            $quantity = CryptoQuantity::fromFloat($request_attributes['quantity'], $is_divisible);

            // compose the send
            $request_id = (isset($request_attributes['requestId']) AND strlen($request_attributes['requestId'])) ? $request_attributes['requestId'] : Uuid::uuid4()->toString();

            // create attibutes
            $create_attributes = [];
            $create_attributes['user_id']            = $user['id'];
            $create_attributes['payment_address_id'] = $payment_address['id'];
            $create_attributes['destination']        = $request_attributes['destination'];
            $create_attributes['quantity_sat']       = $quantity->getValueForCounterparty();
            $create_attributes['asset']              = $request_attributes['asset'];
            if (isset($request_attributes['dust_size'])) {
                $create_attributes['dust_size_sat'] = CurrencyUtil::valueToSatoshis($request_attributes['dust_size']);
            }

            list($send_model, $transaction_proposal) = $send_respository->executeWithNewLockedSendByRequestID($request_id, $create_attributes, 
                function($locked_send) use ($copay_client, $request_attributes, $payment_address, $is_divisible, $send_respository) {
                    $wallet = $payment_address->getCopayWallet();
                    $copay_client = $payment_address->getCopayClient($wallet);

                    $args = [
                        'address'     => $locked_send['destination'],
                        'amountSat'   => $locked_send['quantity_sat'],
                        'token'       => $locked_send['asset'],
                        'feePerKBSat' => CurrencyUtil::valueToSatoshis($request_attributes['feePerKB']),
                        'divisible'   => $is_divisible,
                    ];

                    // dust size
                    if ($locked_send['dust_size_sat'] !== null) {
                        $args['dustSize'] = $locked_send['dust_size_sat'];
                    }

                    // message
                    if (isset($request_attributes['message'])) {
                        $args['message'] = $request_attributes['message'];
                    }

                    $transaction_proposal = $copay_client->proposePublishAndSignTransaction($wallet, $args);
                    $send_respository->update($locked_send, [
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


    /**
     * delete a send
     *
     * @return Response
     */
    public function deleteSend(APIControllerHelper $helper, SendRepository $send_respository, PaymentAddressRepository $payment_address_repository, $send_uuid) {
        try {
            // get the user and send by id
            $user = Auth::getUser();
            if (!$user) { throw new Exception("User not found", 1); }
            $send = $helper->requireResourceOwnedByUser($send_uuid, $user, $send_respository);

            // get the payment address
            $payment_address = $payment_address_repository->findById($send['payment_address_id']);

            // tell copay to remove the proposal
            $wallet = $payment_address->getCopayWallet();
            $copay_client = $payment_address->getCopayClient($wallet);
            $tx_proposal_id = $send['tx_proposal_id'];
            $copay_client->deleteTransactionProposal($wallet, $tx_proposal_id);

            // delete the send
            $send_respository->delete($send);

            // done - return an empty response
            return $helper->buildJSONResponse([], 204);

        } catch (Exception $e) {
            EventLog::logError('submitSend.error', $e);
            throw $e;
        }
    }

    // ------------------------------------------------------------------------
    
    protected function buildRecordLockKey($payment_address) {
        return 'compose/'.$payment_address['uuid'];
    }    
}
