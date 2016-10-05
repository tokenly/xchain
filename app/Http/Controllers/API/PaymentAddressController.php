<?php 

namespace App\Http\Controllers\API;

use App\Blockchain\Reconciliation\BlockchainBalanceReconciler;
use App\Blockchain\Reconciliation\BlockchainTXOReconciler;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\PaymentAddress\CreateMultisigAddressRequest;
use App\Http\Requests\API\PaymentAddress\CreatePaymentAddressRequest;
use App\Http\Requests\API\PaymentAddress\CreateUnmanagedAddressRequest;
use App\Http\Requests\API\PaymentAddress\UpdatePaymentAddressRequest;
use App\Models\APICall;
use App\Models\PaymentAddress;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\APICallRepository;
use App\Repositories\AccountRepository;
use App\Repositories\LedgerEntryRepository;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\SendRepository;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\CopayClient\CopayClient;
use Tokenly\CopayClient\CopayWallet;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class PaymentAddressController extends APIController {

    const TYPE_PAYMENT_ADDRESS   = 1;
    const TYPE_UNMANAGED_ADDRESS = 2;
    const TYPE_MULTISIG_ADDRESS  = 3;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository)
    {
        return $helper->index($payment_address_respository);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(APIControllerHelper $helper, CreatePaymentAddressRequest $request, PaymentAddressRepository $payment_address_respository, Guard $auth)
    {
        $attributes = $request->only(array_keys($request->rules()));
        return $helper->transformResourceForOutput($this->buildNewPaymentAddressFromRequestAttributes($attributes, $payment_address_respository, $auth, self::TYPE_PAYMENT_ADDRESS));
    }

    /**
     * Store a new unmanaged address
     *
     * @return Response
     */
    public function createUnmanaged(APIControllerHelper $helper, CreateUnmanagedAddressRequest $request, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $address_respository, BlockchainBalanceReconciler $blockchain_balance_reconciler, BlockchainTXOReconciler $blockchain_txo_reconciler, APICallRepository $api_call_repository, Guard $auth) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $request_attributes = $request->only(array_keys($request->rules()));
        $api_call = $api_call_repository->create([
            'user_id' => $user['id'],
            'details' => [
                'method' => 'api/unmanaged/addresses',
                'args'   => $request_attributes,
            ],
        ]);

        $payment_address_attributes = $request_attributes;
        unset($payment_address_attributes['webhookEndpoint']);
        $payment_address = $this->buildNewPaymentAddressFromRequestAttributes($payment_address_attributes, $payment_address_respository, $auth, self::TYPE_UNMANAGED_ADDRESS, $blockchain_balance_reconciler, $blockchain_txo_reconciler, $api_call);
        $output = $payment_address->serializeForAPI();


        // if webhook endpoint was specified, create monitors and add their IDs to the response
        if (strlen($request_attributes['webhookEndpoint'])) {
            $monitor_vars = [
                'address'         => $request_attributes['address'],
                'webhookEndpoint' => $request_attributes['webhookEndpoint'],
                'monitorType'     => 'receive',
                'active'          => true,
                'user_id'         => $user['id'],
            ];
            // receive monitor
            $receive_monitor = $address_respository->create($monitor_vars);
            EventLog::log('monitor.created', json_decode(json_encode($receive_monitor)));

            // send monitor
            $monitor_vars['monitorType'] = 'send';
            $send_monitor = $address_respository->create($monitor_vars);
            EventLog::log('monitor.created', json_decode(json_encode($send_monitor)));

            $output['receiveMonitorId'] = $receive_monitor['uuid'];
            $output['sendMonitorId']    = $send_monitor['uuid'];
        }

        return $helper->buildJSONResponse($output);
    }


    /**
     * Destroy an unmanaged address
     *
     * @return Response
     */
    public function destroyUnmanaged(APIControllerHelper $helper, LedgerEntryRepository $ledger, AccountRepository $account_repository, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitor_respository, NotificationRepository $notification_repository, TXORepository $txo_repository, SendRepository $send_repository, Guard $auth, $payment_address_uuid) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $address_model = $payment_address_respository->findByUuid($payment_address_uuid);
        if (!$address_model) { return new JsonResponse(['message' => 'Not found'], 404); }

        // verify owner
        if ($address_model['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to delete this address'], 403); }

        return DB::transaction(function() use ($helper, $payment_address_respository, $payment_address_uuid, $address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository, $send_repository) {
            $this->destroyPaymentAddressDependencies($address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository, $send_repository);

            // delete payment address
            EventLog::log('address.deleteUnmanaged', $address_model->serializeForAPI());
            return $helper->destroy($payment_address_respository, $payment_address_uuid, $user['id']);
        });
    }

    /**
     * Store a new multisig address
     *
     * @return Response
     */
    public function createMultisig(APIControllerHelper $helper, CreateMultisigAddressRequest $request, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitored_address_repository, CopayClient $copay_client, Guard $auth) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $request_attributes = $request->only(array_keys($request->rules()));
        $payment_address_attributes = $request_attributes;

        // generate the new address secret token first
        $private_key_token = app('Tokenly\TokenGenerator\TokenGenerator')->generateToken(40, 'A');
        $payment_address_attributes['private_key_token'] = $private_key_token;
        $wallet = CopayWallet::newWalletFromPlatformSeedAndWalletSeed(env('BITCOIN_MASTER_KEY'), $private_key_token);

        // get copayer info
        list($copayers_m, $copayers_n) = explode('of', $request_attributes['multisigType']);
        $copayer_name = isset($request_attributes['copayerName']) ? $request_attributes['copayerName'] : env('COPAYER_DEFAULT_NAME', 'XChain');

        // create a wallet on the copay server
        try {
            $wallet_id = $copay_client->createWallet($request_attributes['name'], [
                'm'      => $copayers_m,
                'n'      => $copayers_n,
                'pubKey' => $wallet['walletPubKey']->getHex(),
            ]);
        } catch (Exception $e) {
            EventLog::logError('createWallet.failed', $e, ['name' => $request_attributes['name']]);
            return $helper->newJsonResponseWithErrors("Failed to create multisig wallet");
        }

        // join the wallet on the copay server
        try {
            // Log::debug("\$join_args=".json_encode([$wallet_id, $wallet['walletPrivKey']->getHex(), $wallet['xPubKey']->toExtendedKey(), $wallet['requestPubKey']->getHex(), $copayer_name], 192));
            $wallet_info = $copay_client->joinWallet($wallet_id, $wallet['walletPrivKey'], $wallet['xPubKey'], $wallet['requestPubKey'], $copayer_name);
        } catch (Exception $e) {
            EventLog::logError('joinWallet.failed', $e, ['wallet_id' => $wallet_id, 'name' => $request_attributes['name'], 'copayer_name' => $copayer_name,]);
            return $helper->newJsonResponseWithErrors("Failed to join multisig wallet");
        }

        // add the copay data
        $payment_address_attributes['copay_data'] = [
            'm'            => $copayers_m,
            'n'            => $copayers_n,
            'name'         => $request_attributes['name'],
            'copayer_name' => $copayer_name,
            'id'           => $wallet_id,
        ];


        // clear attributes
        unset($payment_address_attributes['multisigType']);
        unset($payment_address_attributes['name']);
        unset($payment_address_attributes['copayerName']);
        unset($payment_address_attributes['webhookEndpoint']);

        $payment_address = $this->buildNewPaymentAddressFromRequestAttributes($payment_address_attributes, $payment_address_respository, $auth, self::TYPE_MULTISIG_ADDRESS);
        $output = $payment_address->serializeForAPI();

        $webhook_endpoint = (isset($request_attributes['webhookEndpoint']) AND strlen($request_attributes['webhookEndpoint'])) ? $request_attributes['webhookEndpoint'] : null;
        // if webhook endpoint was specified, create a join monitor
        if ($webhook_endpoint) {
            $monitor_vars = [
                'user_id'            => $user['id'],
                'address'            => '',
                'payment_address_id' => $payment_address['id'],
                'webhookEndpoint'    => $request_attributes['webhookEndpoint'],
                'monitorType'        => 'joined',
                'active'             => true,
            ];

            $joined_monitor = $monitored_address_repository->create($monitor_vars);
            $output['joinedMonitorId'] = $joined_monitor['uuid'];
        }

        return $helper->buildJSONResponse($output);
    }


    /**
     * Destroy a multisig address
     *
     * @return Response
     */
    public function destroyMultisig(APIControllerHelper $helper, LedgerEntryRepository $ledger, AccountRepository $account_repository, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitor_respository, NotificationRepository $notification_repository, TXORepository $txo_repository, SendRepository $send_repository, Guard $auth, $payment_address_uuid) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $address_model = $payment_address_respository->findByUuid($payment_address_uuid);
        if (!$address_model) { return new JsonResponse(['message' => 'Not found'], 404); }

        // verify owner
        if ($address_model['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to delete this address'], 403); }

        return DB::transaction(function() use ($helper, $payment_address_respository, $payment_address_uuid, $address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository, $send_repository) {
            $this->destroyPaymentAddressDependencies($address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository, $send_repository);

            // delete payment address
            EventLog::log('address.deleteMultisig', $address_model->serializeForAPI());
            return $helper->destroy($payment_address_respository, $payment_address_uuid, $user['id']);
        });
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, $id)
    {
        return $helper->show($payment_address_respository, $id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(APIControllerHelper $helper, UpdatePaymentAddressRequest $request, PaymentAddressRepository $payment_address_respository, $id)
    {
        return $helper->update($payment_address_respository, $id, $request->only(array_keys($request->rules())));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitor_respository, AccountRepository $account_repository, LedgerEntryRepository $ledger, NotificationRepository $notification_repository, TXORepository $txo_repository, SendRepository $send_repository, $payment_address_uuid)
    {
        $user = Auth::user();
        if (!$user) { throw new Exception("User not found", 1); }

        $address_model = $payment_address_respository->findByUuid($payment_address_uuid);
        if (!$address_model) { return new JsonResponse(['message' => 'Not found'], 404); }

        return DB::transaction(function() use ($helper, $payment_address_respository, $payment_address_uuid, $address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository, $send_repository) {
            $this->destroyPaymentAddressDependencies($address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository, $send_repository);

            // delete payment address
            EventLog::log('monitor.deleteManagedAddress', $address_model->serializeForAPI());
            return $helper->destroy($payment_address_respository, $payment_address_uuid, $user['id']);
        });

    }

    // ------------------------------------------------------------------------

    protected function buildNewPaymentAddressFromRequestAttributes($attributes, PaymentAddressRepository $payment_address_respository, Guard $auth, $address_type, BlockchainBalanceReconciler $blockchain_balance_reconciler=null, BlockchainTXOReconciler $blockchain_txo_reconciler=null, APICall $api_call=null) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // add the user id
        $payment_address_attributes = $attributes;
        $payment_address_attributes['user_id'] = $user['id'];

        // set the address type
        if ($address_type == self::TYPE_MULTISIG_ADDRESS) {
            $payment_address_attributes['address_type'] = PaymentAddress::TYPE_P2SH;
        } else {
            $payment_address_attributes['address_type'] = PaymentAddress::TYPE_P2PKH;
        }

        $payment_address = $payment_address_respository->create($payment_address_attributes);

        if ($address_type == self::TYPE_PAYMENT_ADDRESS) {
            EventLog::log('paymentAddress.created', $payment_address->toArray(), ['uuid', 'user_id', 'address', 'id']);
        } else if ($address_type == self::TYPE_MULTISIG_ADDRESS) {
            EventLog::log('multisigPaymentAddress.created', $payment_address->toArray(), ['uuid', 'user_id', 'address', 'id']);
        } else if ($address_type == self::TYPE_UNMANAGED_ADDRESS) {
            EventLog::log('unmanagedPaymentAddress.created', $payment_address->toArray(), ['uuid', 'user_id', 'address', 'id']);

        }

        // create a default account
        AccountHandler::createDefaultAccount($payment_address);

        // reconcile the address balances from the daemon on creation
        if ($address_type == self::TYPE_UNMANAGED_ADDRESS) {
            if (!$blockchain_balance_reconciler) { throw new Exception("Balance reconciler not found", 1); }
            if (!$blockchain_txo_reconciler) { throw new Exception("Balance TXO reconciler not found", 1); }

            // reconcile balances
            $balance_differences = $blockchain_balance_reconciler->buildDifferences($payment_address);
            $blockchain_balance_reconciler->reconcileDifferences($balance_differences, $payment_address, $api_call);

            // reconcile TXOs
            $txo_differences = $blockchain_txo_reconciler->buildDifferences($payment_address);
            $blockchain_txo_reconciler->reconcileDifferences($txo_differences, $payment_address, $api_call);
        }

        return $payment_address;
    }

    protected function destroyPaymentAddressDependencies($address_model, $user, LedgerEntryRepository $ledger, AccountRepository $account_repository, MonitoredAddressRepository $monitor_respository, NotificationRepository $notification_repository, TXORepository $txo_repository, SendRepository $send_repository) {
        // delete any monitors
        foreach ($monitor_respository->findByAddressAndUserId($address_model['address'], $user['id'])->get() as $monitor) {
            // archive all notifications first
            $notification_repository->findByMonitoredAddressId($address_model['id'])->each(function($notification) use ($notification_repository) {
                $notification_repository->archive($notification);
            });

            EventLog::log('monitor.deleteUnmanagedMonitor', $monitor->serializeForAPI());
            $monitor_respository->delete($monitor);
        }

        // delete each ledger entry and accounts first
        $accounts = $account_repository->findByAddressAndUserID($address_model['id'], $user['id']);
        foreach($accounts as $account) {
            EventLog::log('monitor.deleteUnmanagedAccount', $account->serializeForAPI());

            // delete the ledger entries
            $ledger->deleteByAccount($account);

            // delete the txo entries
            $txo_repository->deleteByAccount($account);

            // delete the account
            $account_repository->delete($account);
        }

        // delete any sends
        $send_repository->deleteByPaymentAddress($address_model);

    }

}
