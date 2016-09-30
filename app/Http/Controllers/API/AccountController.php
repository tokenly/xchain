<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Account\CreateAccountRequest;
use App\Http\Requests\API\Account\UpdateAccountRequest;
use App\Jobs\CreateAccountJob;
use App\Repositories\AccountRepository;
use App\Repositories\PaymentAddressRepository;
use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Rhumsaa\Uuid\Uuid;
use Tokenly\LaravelApiProvider\Filter\IndexRequestFilter;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class AccountController extends APIController {

    use DispatchesJobs;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index($address_uuid, Guard $auth, Request $request, APIControllerHelper $helper, AccountRepository $account_respository, PaymentAddressRepository $payment_address_repository)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

        $resources = $account_respository->findByAddressAndUserID($payment_address['id'], $user['id'], $this->buildAccountFilter($request, $account_respository));

        return $helper->transformResourcesForOutput($resources);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function create(CreateAccountRequest $request, Guard $auth, AccountRepository $account_respository, PaymentAddressRepository $payment_address_repository, APIControllerHelper $helper)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get the monitored address and that it is owned by the user
        $attributes = $request->only(array_keys($request->rules()));
        $payment_address = $helper->requireResourceOwnedByUser($attributes['addressId'], $user, $payment_address_repository);

        // create the account
        $create_vars = $attributes;
        $uuid = Uuid::uuid4()->toString();
        $create_vars['uuid'] = $uuid;
        unset($create_vars['addressId']);
        $this->dispatch(new CreateAccountJob($create_vars, $payment_address));

        return $helper->transformResourceForOutput($account_respository->findByUuid($uuid));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($uuid, Guard $auth, APIControllerHelper $helper, AccountRepository $account_respository)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $resource = $helper->requireResourceOwnedByUser($uuid, $user, $account_respository);

        return $helper->transformResourceForOutput($resource);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update($id, UpdateAccountRequest $request, Guard $auth, AccountRepository $account_respository, APIControllerHelper $helper)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        // get non null attributes only
        $attributes = $request->only(array_keys($request->rules()));
        $non_null_attributes = [];
        foreach($attributes as $k => $v) {
            if ($v !== null) { $non_null_attributes[$k] = $v; }
        }
        if (!$non_null_attributes) { return $helper->buildJSONResponse(['message' => 'nothing to update'], 400); }

        // update
        try {
            return $helper->update($account_respository, $id, $non_null_attributes, $user);
        } catch (Exception $e) {
            if ($e->getCode() >= 400 AND $e->getCode() < 500) {
                throw new HttpResponseException(new JsonResponse(['message' => $e->getMessage()], 400));
            }
            throw $e;
                                    
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(APIControllerHelper $helper, AccountRepository $account_respository, $id)
    {
        return $helper->destroy($account_respository, $id);
    }


    ////////////////////////////////////////////////////////////////////////
    

    protected function buildAccountFilter(Request $request, AccountRepository $account_respository) {
        $definition = $account_respository->getSearchFilterDefinition();
        return IndexRequestFilter::createFromRequest($request, $definition);
    }


}
