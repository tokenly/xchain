<?php

namespace App\Http\Controllers\API;

use App\Blockchain\Composer\ComposerUtil;
use App\Blockchain\Sender\PaymentAddressSender;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Send\CleanupRequest;
use App\Http\Requests\API\Send\ComposeSendRequest;
use App\Http\Requests\API\Send\CreateMultiSendRequest;
use App\Http\Requests\API\Send\CreateSendRequest;
use App\Http\Requests\API\Send\EstimateFeeRequest;
use App\Models\TXO;
use App\Providers\Accounts\Exception\AccountException;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Providers\DateProvider\Facade\DateProvider;
use App\Repositories\APICallRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Rhumsaa\Uuid\Uuid;
use Tokenly\BitcoinPayer\Exception\PaymentException;
use Tokenly\CounterpartySender\CounterpartySender;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class SendController extends APIController {

    const SEND_LOCK_TIMEOUT = 3600; // 1 hour
    const DEFAULT_FEE_PRIORITY = 'medium';

    /**
     * Create and execute a send
     *
     * @return Response
     */
    public function create(APIControllerHelper $helper, CreateSendRequest $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        return $this->executeSend($helper, $request, $payment_address_respository, $send_respository, $address_sender, $auth, $api_call_repository, $id);
    }

    public function createMultisend(APIControllerHelper $helper, CreateMultiSendRequest $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        return $this->executeSend($helper, $request, $payment_address_respository, $send_respository, $address_sender, $auth, $api_call_repository, $id);
    }

    public function estimateFee(APIControllerHelper $helper, EstimateFeeRequest $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        return $this->estimateFeeFromRequest($helper, $request, $payment_address_respository, $send_respository, $address_sender, $auth, $api_call_repository, $id);
    }


    public function cleanup(APIControllerHelper $helper, CleanupRequest $request, PaymentAddressRepository $payment_address_respository, TXORepository $txo_repository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        return $this->cleanupFromRequest($helper, $request, $payment_address_respository, $txo_repository, $send_respository, $address_sender, $auth, $api_call_repository, $id);
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

    // ------------------------------------------------------------------------
    
    protected function executeSend(APIControllerHelper $helper, Request $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the address
        $payment_address = $payment_address_respository->findByUuid($id);
        if (!$payment_address) { return new JsonResponse(['message' => 'address not found'], 404); }

        // make sure this address belongs to this user
        if ($payment_address['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to send from this address'], 403); }

        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));


        // determine if this is a multisend
        $is_multisend = (isset($request_attributes['destinations']) AND $request_attributes['destinations']);
        $is_regular_send = !$is_multisend;

        // normalize destinations
        $destinations = $is_multisend ? $this->normalizeDestinations($request_attributes['destinations']) : '';
        $destination = $is_regular_send ? $request_attributes['destination'] : '';

        // determine variables
        $quantity_sat  = CurrencyUtil::valueToSatoshis($is_multisend ? $this->sumMultisendQuantity($destinations) : $request_attributes['quantity']);
        $asset         = $is_regular_send ? $request_attributes['asset'] : 'BTC';
        $is_sweep      = isset($request_attributes['sweep']) ? !!$request_attributes['sweep'] : false;
        $float_fee     = isset($request_attributes['fee']) ? $request_attributes['fee'] : PaymentAddressSender::DEFAULT_FEE;
        $dust_size     = isset($request_attributes['dust_size']) ? $request_attributes['dust_size'] : PaymentAddressSender::DEFAULT_REGULAR_DUST_SIZE;
        $request_id    = isset($request_attributes['requestId']) ? $request_attributes['requestId'] : Uuid::uuid4()->toString();
        $custom_inputs = isset($request_attributes['utxo_override']) ? $request_attributes['utxo_override'] : false;

        // create attibutes
        $create_attributes = [];
        $create_attributes['user_id']            = $user['id'];
        $create_attributes['payment_address_id'] = $payment_address['id'];
        $create_attributes['destination']        = $destination;
        $create_attributes['quantity_sat']       = $quantity_sat;
        $create_attributes['asset']              = $asset;
        $create_attributes['is_sweep']           = $is_sweep;
        $create_attributes['fee']                = $float_fee;
        $create_attributes['dust_size']          = $dust_size;

        // for multisends
        $create_attributes['destinations']       = $destinations;

        // the transaction must be committed before the lock is release and not after
        //   therefore we must release the lock after this closure completes
        $lock_must_be_released = false;
        $lock_must_be_released_with_delay = false;

        // create a send and lock it immediately
        $send_result = $send_respository->executeWithNewLockedSendByRequestID($request_id, $create_attributes, function($locked_send) 
            use (
                $request_attributes, $create_attributes, $payment_address, $user, $helper, $send_respository, $address_sender, $api_call_repository, $request_id, 
                $is_multisend, $is_regular_send, $quantity_sat, $asset, $destination, $destinations, $is_sweep, $float_fee, $dust_size, 
                &$lock_must_be_released, &$lock_must_be_released_with_delay, $custom_inputs
            ) {
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

            $float_quantity = CurrencyUtil::satoshisToValue($quantity_sat);

            // send
            EventLog::log('send.requested', array_merge($request_attributes, $create_attributes));
            if ($is_sweep) {
                try {
                    // get lock
                    $lock_acquired = AccountHandler::acquirePaymentAddressLock($payment_address);
                    if ($lock_acquired) { $lock_must_be_released = true; }

                    $txid = $address_sender->sweepAllAssets($payment_address, $request_attributes['destination'], $float_fee);

                    // clear all balances from all accounts
                    AccountHandler::zeroAllBalances($payment_address, $api_call);

                    // release the account lock with a slight delay
                    if ($lock_acquired) { $lock_must_be_released_with_delay = true; }
                } catch (PaymentException $e) {
                    EventLog::logError('error.sweep', $e);
                    return new JsonResponse(['message' => $e->getMessage()], 500); 
                } catch (Exception $e) {
                    EventLog::logError('error.sweep', $e);
                    return new JsonResponse(['message' => 'Unable to complete this request'], 500); 
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
                    // Log::debug("\$account=".json_encode($account, 192));

                    // get lock
                    $lock_acquired = AccountHandler::acquirePaymentAddressLock($payment_address);
                    if ($lock_acquired) { $lock_must_be_released = true; }

                    // whether to spend unconfirmed balances
                    $allow_unconfirmed = isset($request_attributes['unconfirmed']) ? $request_attributes['unconfirmed'] : false;
                    // Log::debug("\$allow_unconfirmed=".json_encode($allow_unconfirmed, 192));

                    // validate that the funds are available, bypass this if custom_inputs used
                    if(!$custom_inputs){
                        $assets_to_send = ComposerUtil::buildAssetQuantities($float_quantity, $asset, $float_fee, $dust_size);
                        if ($allow_unconfirmed) {
                            $has_enough_funds = AccountHandler::accountHasSufficientFunds($account, $assets_to_send);
                        } else {
                            $has_enough_funds = AccountHandler::accountHasSufficientConfirmedFunds($account, $assets_to_send);
                        }
                        if (!$has_enough_funds) {
                            EventLog::logError('error.send.insufficient', ['address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset]);
                            return new JsonResponse(['message' => "This account does not have sufficient".($allow_unconfirmed ? '' : ' confirmed')." funds available."], 400);
                        }
                    }


                    // send the funds
                    EventLog::log('send.begin', ['request_id' => $request_id, 'address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset, 'destination' => ($is_multisend ? $destinations : $destination), 'custom_inputs' => $custom_inputs]);
                    $txid = $address_sender->sendByRequestID($request_id, $payment_address, ($is_multisend ? $destinations : $destination), $float_quantity, $asset, $float_fee, $dust_size, false, $custom_inputs);
                    EventLog::log('send.complete', ['txid' => $txid, 'request_id' => $request_id, 'address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset, 'destination' => ($is_multisend ? $destinations : $destination)]);


                    // tag funds as sent with the txid
                    try {
                        $assets_to_send = ComposerUtil::buildAssetQuantities($float_quantity, $asset, $float_fee, $dust_size);
                        if ($allow_unconfirmed) {
                            AccountHandler::markAccountFundsAsSending($account, $assets_to_send, $txid);
                        } else {
                            AccountHandler::markConfirmedAccountFundsAsSending($account, $assets_to_send, $txid);
                            // Log::debug("After marking confirmed funds as sent, all accounts for ${account['name']}: ".json_encode(app('App\Repositories\LedgerEntryRepository')->accountBalancesByAsset($account, null), 192));
                            // Log::debug("After marking confirmed funds as sent, all accounts for default: ".json_encode(app('App\Repositories\LedgerEntryRepository')->accountBalancesByAsset(AccountHandler::getAccount($payment_address), null), 192));
                        }
                    } catch (Exception $e) {
                        // we must catch the error here and return a success to the client
                        //  because the transaction was pushed to the bitcoin network already
                        EventLog::logError('error.postPay', $e, ['txid' => $txid, 'request_id' => $request_id, 'address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset, 'destination' => ($is_multisend ? $destinations : $destination)]);
                    }

                    // release the account lock
                    if ($lock_acquired) { $lock_must_be_released_with_delay = true; }


                } catch (AccountException $e) {
                    EventLog::logError('error.pay', $e);
                    return new JsonResponse(['message' => $e->getMessage(), 'errorName' => $e->getErrorName()], $e->getStatusCode()); 
                    
                } catch (PaymentException $e) {
                    EventLog::logError('error.pay', $e);
                    return new JsonResponse(['message' => $e->getMessage()], 500);

                } catch (Exception $e) {
                    EventLog::logError('error.pay', $e);
                    return new JsonResponse(['message' => 'Unable to complete this request'], 500); 
                }
            }

            $attributes = [];
            $attributes['sent'] = DateProvider::now();
            $attributes['txid'] = $txid;

            EventLog::log('send.complete', $attributes);

            // update and send response
            $send_respository->update($locked_send, $attributes);
            return $helper->buildJSONResponse($locked_send->serializeForAPI());
        }, self::SEND_LOCK_TIMEOUT);

        // make sure to release the lock
        if ($lock_must_be_released_with_delay) {
            $this->releasePaymentAddressLockWithDelay($payment_address);
        } else if ($lock_must_be_released) {
            AccountHandler::releasePaymentAddressLock($payment_address);
        }

        return $send_result;
    }

    protected function estimateFeeFromRequest(APIControllerHelper $helper, Request $request, PaymentAddressRepository $payment_address_respository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the address
        $payment_address = $payment_address_respository->findByUuid($id);
        if (!$payment_address) { return new JsonResponse(['message' => 'address not found'], 404); }

        // make sure this address belongs to this user
        if ($payment_address['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to send from this address'], 403); }

        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        // determine if this is a multisend
        $is_multisend = (isset($request_attributes['destinations']) AND $request_attributes['destinations']);
        $is_regular_send = !$is_multisend;

        // normalize destinations
        $destinations = $is_multisend ? $this->normalizeDestinations($request_attributes['destinations']) : '';
        $destination = $is_regular_send ? $request_attributes['destination'] : '';

        // determine variables
        $quantity_sat = CurrencyUtil::valueToSatoshis($is_multisend ? $this->sumMultisendQuantity($destinations) : $request_attributes['quantity']);
        $asset        = $is_regular_send ? $request_attributes['asset'] : 'BTC';
        $is_sweep     = isset($request_attributes['sweep']) ? !!$request_attributes['sweep'] : false;
        $dust_size    = isset($request_attributes['dust_size']) ? $request_attributes['dust_size'] : PaymentAddressSender::DEFAULT_REGULAR_DUST_SIZE;

        // create attibutes
        $create_attributes = [];
        $create_attributes['user_id']            = $user['id'];
        $create_attributes['payment_address_id'] = $payment_address['id'];
        $create_attributes['destination']        = $destination;
        $create_attributes['quantity_sat']       = $quantity_sat;
        $create_attributes['asset']              = $asset;

        // for multisends
        $create_attributes['destinations']       = $destinations;

        $float_quantity = CurrencyUtil::satoshisToValue($quantity_sat);

        try {
            // get the account
            $account_name = ((isset($request_attributes['account']) AND strlen($request_attributes['account'])) ? $request_attributes['account'] : 'default');
            $account = AccountHandler::getAccount($payment_address, $account_name);
            if (!$account) {
                EventLog::logError('error.send.accountMissing', ['address_id' => $payment_address['id'], 'account' => $account_name]);
                return new JsonResponse(['message' => "This account did not exist."], 404);
            }
            // Log::debug("\$account=".json_encode($account, 192));

            // whether to spend unconfirmed balances
            $allow_unconfirmed = isset($request_attributes['unconfirmed']) ? $request_attributes['unconfirmed'] : false;
            // Log::debug("\$allow_unconfirmed=".json_encode($allow_unconfirmed, 192));

            // validate that the funds are available (with a fee of 0)
            $float_fee = 0;
            $assets_to_send = ComposerUtil::buildAssetQuantities($float_quantity, $asset, $float_fee, $dust_size);
            if ($allow_unconfirmed) {
                $has_enough_funds = AccountHandler::accountHasSufficientFunds($account, $assets_to_send);
            } else {
                $has_enough_funds = AccountHandler::accountHasSufficientConfirmedFunds($account, $assets_to_send);
            }
            if (!$has_enough_funds) {
                EventLog::logError('error.send.insufficient', ['address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset]);
                return new JsonResponse(['message' => "This account does not have sufficient".($allow_unconfirmed ? '' : ' confirmed')." funds available."], 400);
            }

            // calculate the fee
            EventLog::log('estimateFee.begin', ['address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset, 'destination' => ($is_multisend ? $destinations : $destination)]);
            $fee_info = $address_sender->buildFeeEstimateInfo($payment_address, ($is_multisend ? $destinations : $destination), $float_quantity, $asset, $dust_size);
            EventLog::log('estimateFee.complete', ['fees' => $fee_info, 'address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset, 'destination' => ($is_multisend ? $destinations : $destination)]);

        } catch (Exception $e) {
            EventLog::logError('error.estimateFee', $e);
            return new JsonResponse(['message' => 'Unable to complete this request'], 500); 
        }

        $fees_response = ['fees' => [], 'size' => $fee_info['size']];
        foreach($fee_info['fees'] as $level => $fee_satoshi) {
            $fees_response['fees'][$level] = CurrencyUtil::satoshisToValue($fee_satoshi);
            $fees_response['fees'][$level.'Sat'] = $fee_satoshi;
        }

        return $helper->buildJSONResponse($fees_response);
    }

    protected function cleanupFromRequest(APIControllerHelper $helper, Request $request, PaymentAddressRepository $payment_address_respository, TXORepository $txo_repository, SendRepository $send_respository, PaymentAddressSender $address_sender, Guard $auth, APICallRepository $api_call_repository, $id) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the address
        $payment_address = $payment_address_respository->findByUuid($id);
        if (!$payment_address) { return new JsonResponse(['message' => 'address not found'], 404); }

        // make sure this address belongs to this user
        if ($payment_address['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to send from this address'], 403); }

        // attributes
        $request_attributes = $request->only(array_keys($request->rules()));

        // get the utxos
        $confirmed_txos = iterator_to_array($txo_repository->findByPaymentAddress($payment_address, [TXO::CONFIRMED], true));

        $utxo_count_to_consolidate = min($confirmed_txos, $request_attributes['max_utxos']);
        $fee_priority = isset($request_attributes['priority']) ? $request_attributes['priority'] : self::DEFAULT_FEE_PRIORITY;


        $txid = null;
        $before_utxos_count = count($confirmed_txos);
        $after_utxos_count = $before_utxos_count;
        $cleaned_up = false;
        if ($utxo_count_to_consolidate > 1) {
            $cleaned_up = true;

            // build the send transaction
            $composed_transaction_object = $address_sender->consolidateUTXOs($payment_address, $utxo_count_to_consolidate, $fee_priority);
            $txid = $composed_transaction_object->getTxId();
            $txos_sent = count($composed_transaction_object->getInputUtxos());
            
            $after_utxos_count = $before_utxos_count - $txos_sent + 1;
        }

        $cleanup_response = [
            'before_utxos_count' => $before_utxos_count,
            'after_utxos_count'  => $after_utxos_count,
            'cleaned_up'         => $cleaned_up,
            'txid'               => $txid,
        ];


        return $helper->buildJSONResponse($cleanup_response);
    }
        
    protected function releasePaymentAddressLockWithDelay($payment_address) {
        if (app()->environment() != 'testing') {
            $delay = 1500000;
            Log::debug("delaying for ".($delay/1000)." ms before releasing payment address lock");
            usleep($delay);
        }

        AccountHandler::releasePaymentAddressLock($payment_address);
    }

    protected function normalizeDestinations($raw_destinations) {
        $destinations = [];
        foreach($raw_destinations as $raw_destination) {
            $destinations[] = [$raw_destination['address'], $raw_destination['amount']];
        }
        return $destinations;
    }

    // returns float
    protected function sumMultisendQuantity($destinations) {
        $sum = 0;
        foreach($destinations as $destination) {
            $sum += $destination[1];
        }
        return $sum;
    }

}
