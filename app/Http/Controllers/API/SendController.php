<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\PaymentAddressSender;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Send\CreateSendRequest;
use App\Providers\Accounts\Exception\AccountException;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\APICallRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Illuminate\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinPayer\Exception\PaymentException;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class SendController extends APIController {

    const SEND_LOCK_TIMEOUT = 3600; // 1 hour

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function create(APIControllerHelper $helper, CreateSendRequest $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the address
        $payment_address = $payment_address_respository->findByUuid($id);
        if (!$payment_address) { return new JsonResponse(['message' => 'address not found'], 404); }

        // make sure this address belongs to this user
        if ($payment_address['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to send from this address'], 403); }

        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        // create a send and lock it immediately
        $request_id = isset($request_attributes['requestId']) ? $request_attributes['requestId'] : Uuid::uuid4()->toString();

        $create_attributes = [];
        $create_attributes['user_id']            = $user['id'];
        $create_attributes['payment_address_id'] = $payment_address['id'];
        $create_attributes['destination']        = $request_attributes['destination'];
        $create_attributes['quantity_sat']       = CurrencyUtil::valueToSatoshis($request_attributes['quantity']);
        $create_attributes['asset']              = $request_attributes['asset'];
        return $send_respository->executeWithNewLockedSendByRequestID($request_id, $create_attributes, function($locked_send) use ($request_attributes, $payment_address, $user, $helper, $send_respository, $address_sender, $api_call_repository) {
            $api_call = $api_call_repository->create([
                'user_id' => $user['id'],
                'details' => [
                    'method' => 'api/v1/sends/'.$payment_address['uuid'],
                    'args'   => $request_attributes,
                ],
            ]);


            // if a send already exists by this request_id, just return it
            if (isset($locked_send['txid']) && strlen($locked_send['txid'])) {
                EventLog::log('send.alreadyFound', $locked_send);
                return $helper->transformResourceForOutput($locked_send);
            }

            // send
            EventLog::log('send.requested', $request_attributes);
            $float_fee = isset($request_attributes['fee']) ? $request_attributes['fee'] : PaymentAddressSender::DEFAULT_FEE;
            $dust_size = isset($request_attributes['dust_size']) ? $request_attributes['dust_size'] : PaymentAddressSender::DEFAULT_REGULAR_DUST_SIZE;
            $is_sweep = isset($request_attributes['sweep']) ? !!$request_attributes['sweep'] : false;
            if ($is_sweep) {
                try {
                    // get lock
                    $lock_acquired = AccountHandler::acquirePaymentAddressLock($payment_address);

                    list($txid, $float_balance_sent) = $address_sender->sweepAllAssets($payment_address, $request_attributes['destination'], $float_fee);
                    $quantity_sat = CurrencyUtil::valueToSatoshis($float_balance_sent);

                    // clear all balances from all accounts
                    AccountHandler::zeroAllBalances($payment_address, $api_call);

                    // release the account lock
                    if ($lock_acquired) { AccountHandler::releasePaymentAddressLock($payment_address); }
                } catch (PaymentException $e) {
                    if ($lock_acquired) { AccountHandler::releasePaymentAddressLock($payment_address); }

                    EventLog::logError('error.sweep', $e);
                    return new JsonResponse(['message' => $e->getMessage()], 500); 
                }
            } else {
                try {
                    // get the account
                    $account_name = ((isset($request_attributes['account']) AND strlen($request_attributes['account'])) ? $request_attributes['account'] : 'default');
                    $account = AccountHandler::getAccount($payment_address, $account_name);
                    if (!$account) {
                        EventLog::logError('error.send.accountMissing', ['address_id' => $payment_address['id'], 'account' => $account_name]);
                        return new JsonResponse(['message' => "This account did not exist."], 404);
                    }
                    Log::debug("\$account=".json_encode($account, 192));

                    // get lock
                    $lock_acquired = AccountHandler::acquirePaymentAddressLock($payment_address);

                    // whether to spend unconfirmed balances
                    $allow_unconfirmed = isset($request_attributes['unconfirmed']) ? $request_attributes['unconfirmed'] : false;
                    Log::debug("\$allow_unconfirmed=".json_encode($allow_unconfirmed, 192));

                    // validate that the funds are available
                    if ($allow_unconfirmed) {
                        $has_enough_funds = AccountHandler::accountHasSufficientFunds($account, $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $dust_size);
                    } else {
                        $has_enough_funds = AccountHandler::accountHasSufficientConfirmedFunds($account, $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $dust_size);
                    }
                    if (!$has_enough_funds) {
                        EventLog::logError('error.send.insufficient', ['address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $request_attributes['quantity'], 'asset' => $request_attributes['asset']]);
                        return new JsonResponse(['message' => "This account does not have sufficient".($allow_unconfirmed ? '' : ' confirmed')." funds available."], 400);
                    }


                    // send the funds
                    $txid = $address_sender->send($payment_address, $request_attributes['destination'], $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $dust_size);
                    $quantity_sat = CurrencyUtil::valueToSatoshis($request_attributes['quantity']);


                    // tag funds as sent with the txid
                    if ($allow_unconfirmed) {
                        AccountHandler::markAccountFundsAsSending($account, $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $dust_size, $txid);
                    } else {
                        AccountHandler::markConfirmedAccountFundsAsSending($account, $request_attributes['quantity'], $request_attributes['asset'], $float_fee, $dust_size, $txid);
                    }

                    // release the account lock
                    if ($lock_acquired) { AccountHandler::releasePaymentAddressLock($payment_address); }

                } catch (AccountException $e) {
                    if ($lock_acquired) { AccountHandler::releasePaymentAddressLock($payment_address); }

                    EventLog::logError('error.pay', $e);
                    return new JsonResponse(['message' => $e->getMessage(), 'errorName' => $e->getErrorName()], $e->getStatusCode()); 
                    
                } catch (PaymentException $e) {
                    if ($lock_acquired) { AccountHandler::releasePaymentAddressLock($payment_address); }

                    EventLog::logError('error.pay', $e);
                    return new JsonResponse(['message' => $e->getMessage()], 500); 
                }
            }

            $attributes = [];
            $attributes['user_id']            = $user['id'];
            $attributes['payment_address_id'] = $payment_address['id'];
            $attributes['sent']               = time();
            $attributes['destination']        = $request_attributes['destination'];
            $attributes['quantity_sat']       = $quantity_sat;
            $attributes['fee']                = $request_attributes['fee'];
            $attributes['dust_size']          = $request_attributes['dust_size'];
            $attributes['asset']              = $request_attributes['asset'];
            $attributes['is_sweep']           = $is_sweep;
            $attributes['txid']               = $txid;

            EventLog::log('send.complete', $attributes);

            // update and send response
            $send_respository->update($locked_send, $attributes);
            return $helper->buildJSONResponse($locked_send->serializeForAPI());
        }, self::SEND_LOCK_TIMEOUT);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIControllerHelper $helper, SendRepository $send_respository, $id)
    {
        return $helper->show($send_respository, $id);
    }



}
