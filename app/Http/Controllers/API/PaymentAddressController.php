<?php 

namespace App\Http\Controllers\API;

use App\Blockchain\Reconciliation\BlockchainBalanceReconciler;
use App\Blockchain\Reconciliation\BlockchainTXOReconciler;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\PaymentAddress\CreatePaymentAddressRequest;
use App\Http\Requests\API\PaymentAddress\CreateUnmanagedAddressRequest;
use App\Http\Requests\API\PaymentAddress\UpdatePaymentAddressRequest;
use App\Models\APICall;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\APICallRepository;
use App\Repositories\AccountRepository;
use App\Repositories\LedgerEntryRepository;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class PaymentAddressController extends APIController {

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
        return $helper->transformResourceForOutput($this->buildNewPaymentAddressFromRequestAttributes($attributes, $payment_address_respository, $auth, true));
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
        $payment_address = $this->buildNewPaymentAddressFromRequestAttributes($payment_address_attributes, $payment_address_respository, $auth, false, $blockchain_balance_reconciler, $blockchain_txo_reconciler, $api_call);
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
     * Destroy a new unmanaged address
     *
     * @return Response
     */
    public function destroyUnmanaged(APIControllerHelper $helper, LedgerEntryRepository $ledger, AccountRepository $account_repository, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitor_respository, NotificationRepository $notification_repository, TXORepository $txo_repository, Guard $auth, $payment_address_uuid) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $address_model = $payment_address_respository->findByUuid($payment_address_uuid);
        if (!$address_model) { return new JsonResponse(['message' => 'Not found'], 404); }

        // verify owner
        if ($address_model['user_id'] != $user['id']) { return new JsonResponse(['message' => 'Not authorized to delete this address'], 403); }

        return DB::transaction(function() use ($helper, $payment_address_respository, $payment_address_uuid, $address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository) {
            $this->destroyPaymentAddressDependencies($address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository);

            // delete payment address
            EventLog::log('address.deleteUnmanaged', $address_model->serializeForAPI());
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
    public function destroy(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitor_respository, AccountRepository $account_repository, LedgerEntryRepository $ledger, NotificationRepository $notification_repository, TXORepository $txo_repository, $payment_address_uuid)
    {
        $user = Auth::user();
        if (!$user) { throw new Exception("User not found", 1); }

        $address_model = $payment_address_respository->findByUuid($payment_address_uuid);
        if (!$address_model) { return new JsonResponse(['message' => 'Not found'], 404); }

        return DB::transaction(function() use ($helper, $payment_address_respository, $payment_address_uuid, $address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository) {
            $this->destroyPaymentAddressDependencies($address_model, $user, $ledger, $account_repository, $monitor_respository, $notification_repository, $txo_repository);

            // delete payment address
            EventLog::log('monitor.deleteManagedAddress', $address_model->serializeForAPI());
            return $helper->destroy($payment_address_respository, $payment_address_uuid, $user['id']);
        });

    }

    // ------------------------------------------------------------------------

    protected function buildNewPaymentAddressFromRequestAttributes($attributes, PaymentAddressRepository $payment_address_respository, Guard $auth, $is_managed, BlockchainBalanceReconciler $blockchain_balance_reconciler=null, BlockchainTXOReconciler $blockchain_txo_reconciler=null, APICall $api_call=null) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // add the user id
        $attributes['user_id'] = $user['id'];

        $payment_address = $payment_address_respository->create($attributes);

        if ($is_managed) {
            EventLog::log('paymentAddress.created', $payment_address->toArray(), ['uuid', 'user_id', 'address', 'id']);
        } else {
            EventLog::log('unmanagedPaymentAddress.created', $payment_address->toArray(), ['uuid', 'user_id', 'address', 'id']);
        }

        // create a default account
        AccountHandler::createDefaultAccount($payment_address);

        // reconcile the address balances from the daemon on creation
        if (!$is_managed) {
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

    protected function destroyPaymentAddressDependencies($address_model, $user, LedgerEntryRepository $ledger, AccountRepository $account_repository, MonitoredAddressRepository $monitor_respository, NotificationRepository $notification_repository, TXORepository $txo_repository) {
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
    }

}
