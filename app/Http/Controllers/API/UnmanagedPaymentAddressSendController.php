<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Composer\SendComposer;
use App\Blockchain\Sender\Exception\BitcoinDaemonException;
use App\Blockchain\Sender\PaymentAddressSender;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Send\ComposeSendRequest;
use App\Repositories\APICallRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Exception;
use Illuminate\Auth\Guard;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\RecordLock\Facade\RecordLock;

class UnmanagedPaymentAddressSendController extends APIController {

    const SEND_LOCK_TIMEOUT = 3600; // 1 hour
    const COMPOSE_LOCK_TIME = 300;  // 5 minutes

    /**
     * Create and execute a send
     *
     * @return Response
     */
    public function composeSend(APIControllerHelper $helper, ComposeSendRequest $request, SendComposer $send_composer, APICallRepository $api_call_repository, SendRepository $send_respository, PaymentAddressRepository $payment_address_repository, Guard $auth, $address_uuid) {
        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the address
        $payment_address = $payment_address_repository->findByUuid($address_uuid);
        if (!$payment_address) { throw new HttpResponseException(new JsonResponse(['message' => 'address not found'], 404)); }

        // make sure this address belongs to this user
        if ($payment_address['user_id'] != $user['id']) { throw new HttpResponseException(new JsonResponse(['message' => 'Not authorized to send from this address'], 403)); }


        try {
            $acquired_lock = false;
            $record_lock_key = $this->buildRecordLockKey($payment_address);
            $wait_time = (app()->environment() == 'testing' ? 1 : 5);
            $acquired_lock = RecordLock::acquireOnce($record_lock_key, $wait_time);
            if (!$acquired_lock) { throw new HttpResponseException(new JsonResponse(['message' => 'Unable to compose a new transaction for this address.  Please submit or revoke any previously composed transactions.'], 400)); }


            // see if there is any send already pending
            $unsigned_transactions_exist = $send_respository->findUnsignedTransactionsByPaymentAddressID($payment_address['id'])->count() > 0;
            Log::debug("\$unsigned_transactions_exist=".json_encode($unsigned_transactions_exist, 192));
            if ($unsigned_transactions_exist) { throw new HttpResponseException(new JsonResponse(['message' => 'Unable to compose a new transaction for this address because one already exists.  Please submit or revoke any previously composed transactions.'], 400)); }

            $send_model = $send_composer->composeWithNewLockedSend($payment_address, $user, $request_attributes, function($locked_send) use ($api_call_repository, $request, $user, $request_attributes) {
                // called back from the send composer
                //   save the API call
                $api_call = $api_call_repository->create([
                    'user_id' => $user['id'],
                    'details' => [
                        'method' => $request->path(),
                        'args'   => $request_attributes,
                    ],
                ]);
            });

            // release the lock
            RecordLock::release($record_lock_key);
        } catch (Exception $e) {
            EventLog::logError('composeSend.error', $e, $request_attributes);

            if ($acquired_lock) { RecordLock::release($record_lock_key); }

            throw $e;
        }

        $api_data = $send_model->serializeForAPI('composed');

        unset($api_data['destinations']);
        unset($api_data['sweep']);

        // the txid is not the same as the signed transaction will have
        unset($api_data['txid']);

        return $helper->buildJSONResponse($api_data);
    }

    /**
     * Create and execute a send
     *
     * @return Response
     */
    public function revokeSend(APIControllerHelper $helper, Request $request, SendComposer $send_composer, APICallRepository $api_call_repository, SendRepository $send_respository, PaymentAddressRepository $payment_address_repository, Guard $auth, $send_uuid) {
        try {
            $user = $auth->getUser();
            if (!$user) { throw new Exception("User not found", 1); }

            // get the send
            $composed_send = $send_respository->findByUuid($send_uuid);
            if (!$composed_send) { throw new HttpResponseException(new JsonResponse(['message' => 'composed send not found'], 404)); }

            // get the address
            $payment_address = $payment_address_repository->findById($composed_send['payment_address_id']);
            if (!$payment_address) { throw new HttpResponseException(new JsonResponse(['message' => 'address not found'], 404)); }

            // make sure this address belongs to this user
            if ($payment_address['user_id'] != $user['id']) { throw new HttpResponseException(new JsonResponse(['message' => 'Not authorized to send from this address'], 403)); }

            // revoke the composed send
            $send_composer->revokeSend($composed_send, $payment_address);

            // done - return an empty response
            return $helper->buildJSONResponse([], 204);

        } catch (Exception $e) {
            EventLog::logError('revokeSend.error', $e);
            throw $e;
        }
    }


    /**
     * Create and execute a send
     *
     * @return Response
     */
    public function submitSend(APIControllerHelper $helper, Request $request, PaymentAddressSender $payment_address_sender, APICallRepository $api_call_repository, SendRepository $send_respository, PaymentAddressRepository $payment_address_repository, Guard $auth, $send_uuid) {
        try {
            $user = $auth->getUser();
            if (!$user) { throw new Exception("User not found", 1); }

            // get the send
            $composed_send = $send_respository->findByUuid($send_uuid);
            if (!$composed_send) { throw new HttpResponseException(new JsonResponse(['message' => 'composed send not found'], 404)); }

            // get the address
            $payment_address = $payment_address_repository->findById($composed_send['payment_address_id']);
            if (!$payment_address) { throw new HttpResponseException(new JsonResponse(['message' => 'address not found'], 404)); }

            // make sure this address belongs to this user
            if ($payment_address['user_id'] != $user['id']) { throw new HttpResponseException(new JsonResponse(['message' => 'Not authorized to send from this address'], 403)); }

            // check the signed send
            $request_attributes = $request->all();
            $signed_transaction_hex = $request_attributes['signedTx'];
            // echo "\$signed_transaction_hex: ".json_encode($signed_transaction_hex, 192)."\n";
            if (!strlen($signed_transaction_hex)) {
                throw new HttpResponseException(new JsonResponse(['message' => 'signedTx is required.'], 400));
            }
            try {
                $data = app('TransactionComposerHelper')->parseBTCTransaction($signed_transaction_hex);
            } catch (Exception $e) {
                EventLog::logError('submitSend.validation.error', $e);
                throw new HttpResponseException(new JsonResponse(['message' => 'Invalid signed transaction hex.'], 400));
            }

            // submit the signed transaction to bitcoind
            try {
                $txid = $payment_address_sender->pushSignedComposedTransaction($signed_transaction_hex, $composed_send, $payment_address);
            } catch (BitcoinDaemonException $e) {
                throw new HttpResponseException(new JsonResponse(['message' => $e->getMessage()], 500));
            }

            // update the unsigned transaction
            $send_respository->update($composed_send, ['unsigned_tx' => null, 'utxos' => null, 'unsigned' => false]);

            // done - return an empty response
            return $helper->buildJSONResponse(['txid' => $txid], 200);

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
