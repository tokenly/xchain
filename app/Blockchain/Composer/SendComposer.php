<?php

namespace App\Blockchain\Composer;

use App\Blockchain\Composer\ComposerUtil;
use App\Blockchain\Sender\PaymentAddressSender;
use App\Models\PaymentAddress;
use App\Models\Send;
use App\Providers\Accounts\Exception\AccountException;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\APICallRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use Exception;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class SendComposer {

    const SEND_LOCK_TIMEOUT = 300; // 5 minutes

    function __construct(SendRepository $send_respository, PaymentAddressSender $address_sender) {
        $this->send_respository            = $send_respository;
        $this->address_sender              = $address_sender;
    }

    /**
     * Creates a send model
     *
     * @return App\Models\Send a send model
     */
    public function composeWithNewLockedSend(PaymentAddress $payment_address, $user, $request_attributes, $lock_callback_fn=null) {
        if ($payment_address->isManaged()) {
            throw new HttpResponseException(new JsonResponse(['message' => "This address is an address managed by xchain."], 400));
        }

        // determine if this is a multisend
        $is_multisend = (isset($request_attributes['destinations']) AND $request_attributes['destinations']);
        $is_regular_send = !$is_multisend;

        // normalize destinations
        $destinations = $is_multisend ? $this->normalizeDestinations($request_attributes['destinations']) : '';
        $destination = $is_regular_send ? $request_attributes['destination'] : '';

        // determine variables
        $quantity_sat = CurrencyUtil::valueToSatoshis($is_multisend ? $this->sumMultisendQuantity($destinations) : $request_attributes['quantity']);
        $asset        = $is_regular_send ? $request_attributes['asset'] : 'BTC';
        // $is_sweep     = isset($request_attributes['sweep']) ? !!$request_attributes['sweep'] : false;
        $is_sweep     = false;
        $float_fee    = isset($request_attributes['fee']) ? $request_attributes['fee'] : PaymentAddressSender::DEFAULT_FEE;
        $dust_size    = isset($request_attributes['dust_size']) ? $request_attributes['dust_size'] : PaymentAddressSender::DEFAULT_REGULAR_DUST_SIZE;
        $request_id   = isset($request_attributes['requestId']) ? $request_attributes['requestId'] : Uuid::uuid4()->toString();

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

        // we release the lock after this closure completes
        $lock_must_be_released = false;

        // create a send and lock it immediately
        $send_model = $this->send_respository->executeWithNewLockedSendByRequestID($request_id, $create_attributes, function($locked_send) 
            use (
                $request_attributes, $create_attributes, $payment_address, $user, $request_id, 
                $is_multisend, $is_regular_send, $quantity_sat, $asset, $destination, $destinations, $float_fee, $dust_size, 
                $lock_callback_fn, 
                &$lock_must_be_released
            ) {

            // execute the callback function
            if ($lock_callback_fn !== null AND is_callable($lock_callback_fn)) {
                $lock_callback_fn($locked_send);
            }

            // log the request
            EventLog::log('compose.requested', array_merge(['request_id' => $request_id], $request_attributes, $create_attributes));

            // if a send already exists by this request_id, just return it
            if (isset($locked_send['txid']) && strlen($locked_send['txid'])) {
                EventLog::log('compose.alreadyFound', $locked_send);
                return $locked_send;
            }

            $float_quantity = CurrencyUtil::satoshisToValue($quantity_sat);

            // compose the send
            try {
                // get the account
                $account_name = ((isset($request_attributes['account']) AND strlen($request_attributes['account'])) ? $request_attributes['account'] : 'default');
                $account = AccountHandler::getAccount($payment_address, $account_name);
                if (!$account) {
                    EventLog::logError('error.send.accountMissing', ['address_id' => $payment_address['id'], 'account' => $account_name]);
                    throw new HttpResponseException(new JsonResponse(['message' => "This account did not exist."], 404));
                }

                // get lock
                $lock_acquired = AccountHandler::acquirePaymentAddressLock($payment_address);
                if ($lock_acquired) { $lock_must_be_released = true; }

                // whether to spend unconfirmed balances
                $allow_unconfirmed = isset($request_attributes['unconfirmed']) ? $request_attributes['unconfirmed'] : false;

                // validate that the funds are available
                $assets_to_send = ComposerUtil::buildAssetQuantities($float_quantity, $asset, $float_fee, $dust_size);
                if ($allow_unconfirmed) {
                    $has_enough_funds = AccountHandler::accountHasSufficientFunds($account, $assets_to_send);
                } else {
                    $has_enough_funds = AccountHandler::accountHasSufficientConfirmedFunds($account, $assets_to_send);
                }
                if (!$has_enough_funds) {
                    EventLog::logError('error.send.insufficient', ['address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset]);
                    throw new HttpResponseException(new JsonResponse(['message' => "This account does not have sufficient".($allow_unconfirmed ? '' : ' confirmed')." funds available."], 400));
                }


                // compose the send
                // $txid = $this->address_sender->sendByRequestID($request_id, $payment_address, ($is_multisend ? $destinations : $destination), $float_quantity, $asset, $float_fee, $dust_size);
                $composed_transaction_data = $this->address_sender->composeUnsignedTransactionByRequestID($request_id, $payment_address, ($is_multisend ? $destinations : $destination), $float_quantity, $asset, $float_fee, $dust_size);
                $txid        = $composed_transaction_data['txid'];
                $unsigned_tx = $composed_transaction_data['transaction'];
                $utxos       = $composed_transaction_data['utxos'];

                EventLog::log('compose.complete', ['txid' => $txid, 'request_id' => $request_id, 'address_id' => $payment_address['id'], 'account' => $account_name, 'quantity' => $float_quantity, 'asset' => $asset, 'destination' => ($is_multisend ? $destinations : $destination)]);

                // // tag funds as sent with the txid
                // if ($allow_unconfirmed) {
                //     AccountHandler::markAccountFundsAsSending($account, $float_quantity, $asset, $float_fee, $dust_size, $txid);
                // } else {
                //     AccountHandler::markConfirmedAccountFundsAsSending($account, $float_quantity, $asset, $float_fee, $dust_size, $txid);
                // }

            } catch (Exception $e) {
                EventLog::logError('error.compose', $e);

                if ($lock_must_be_released) {
                    AccountHandler::releasePaymentAddressLock($payment_address);
                }

                // get more details from AccountException
                if ($e instanceof AccountException) {
                    throw new HttpResponseException(new JsonResponse(['message' => $e->getMessage(), 'errorName' => $e->getErrorName()], $e->getStatusCode()));
                }

                // don't wrap HttpResponseException - just throw them as they are
                if ($e instanceof HttpResponseException) { throw $e; }

                // for everything else, throw a generic error
                throw new HttpResponseException(new JsonResponse(['message' => 'Unable to complete this request'], 500));
            }

            $update_vars = [];
            $update_vars['txid']        = $txid;
            $update_vars['unsigned_tx'] = $unsigned_tx;
            $update_vars['utxos']       = $utxos;
            $update_vars['unsigned']    = true;

            // update and send response
            Log::debug("updating locked_send \$update_vars=".json_encode($update_vars, 192));
            $this->send_respository->update($locked_send, $update_vars);

            return $locked_send;
        }, self::SEND_LOCK_TIMEOUT);

        // make sure to release the lock
        if ($lock_must_be_released) {
            AccountHandler::releasePaymentAddressLock($payment_address);
        }

        return $send_model;
    }

    public function revokeSend(Send $composed_send, PaymentAddress $payment_address) {
        return $this->send_respository->executeWithLockedSend($composed_send, function($locked_send) use ($payment_address) {

            $this->send_respository->delete($locked_send);

            EventLog::log('compose.revoked', ['send_id' => $locked_send['id'], 'address_id' => $payment_address['id']]);

            return;
        }, self::SEND_LOCK_TIMEOUT);
    }

    
    // ------------------------------------------------------------------------
    
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
