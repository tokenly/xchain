<?php 

namespace App\Http\Controllers\API;

use App\Blockchain\Reconciliation\BlockchainBalanceReconciler;
use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\PaymentAddress\CreatePaymentAddressRequest;
use App\Http\Requests\API\PaymentAddress\CreateUnmanagedAddressRequest;
use App\Http\Requests\API\PaymentAddress\UpdatePaymentAddressRequest;
use App\Models\APICall;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\APICallRepository;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\PaymentAddressRepository;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function createUnmanaged(APIControllerHelper $helper, CreateUnmanagedAddressRequest $request, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $address_respository, BlockchainBalanceReconciler $blockchain_balance_reconciler, APICallRepository $api_call_repository, Guard $auth) {
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
        $payment_address = $this->buildNewPaymentAddressFromRequestAttributes($payment_address_attributes, $payment_address_respository, $auth, false, $blockchain_balance_reconciler, $api_call);
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
    public function destroyUnmanaged(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, MonitoredAddressRepository $monitor_respository, Guard $auth, $id) {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // delete any monitors
        $address_model = $payment_address_respository->findByUuid($id);
        if ($address_model) {
            foreach ($monitor_respository->findByAddressAndUserId($address_model['address'], $user['id'])->get() as $monitor) {
                EventLog::log('monitor.deleteUnmanagedMonitor', $monitor->serializeForAPI());
                $monitor_respository->delete($monitor);
            }

            EventLog::log('address.deleteUnmanaged', $address_model->serializeForAPI());
        }
        return $helper->destroy($payment_address_respository, $id, $user['id']);
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
    public function destroy(APIControllerHelper $helper, PaymentAddressRepository $payment_address_respository, $id)
    {
        return $helper->destroy($payment_address_respository, $id, Auth::user()['id']);
    }

    // ------------------------------------------------------------------------

    protected function buildNewPaymentAddressFromRequestAttributes($attributes, PaymentAddressRepository $payment_address_respository, Guard $auth, $is_managed, BlockchainBalanceReconciler $blockchain_balance_reconciler=null, APICall $api_call=null) {
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
            $balance_differences = $blockchain_balance_reconciler->buildDifferences($payment_address);
            // Log::debug("\$balance_differences=".json_encode($balance_differences, 192));
            $blockchain_balance_reconciler->reconcileDifferences($balance_differences, $payment_address, $api_call);
        }

        return $payment_address;
    }

}
