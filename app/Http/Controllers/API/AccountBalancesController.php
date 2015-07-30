<?php 

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Base\APIController;
use App\Http\Requests\API\Account\AccountTransferRequest;
use App\Models\LedgerEntry;
use App\Providers\Accounts\Facade\AccountHandler;
use App\Repositories\APICallRepository;
use App\Repositories\AccountRepository;
use App\Repositories\LedgerEntryRepository;
use App\Repositories\PaymentAddressRepository;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tokenly\LaravelApiProvider\Filter\IndexRequestFilter;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class AccountBalancesController extends APIController {

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function balances($address_uuid, Guard $auth, Request $request, APIControllerHelper $helper, AccountRepository $account_respository, PaymentAddressRepository $payment_address_repository, LedgerEntryRepository $ledger_entry_repository)
    {
        return DB::transaction(function() use ($address_uuid, $auth, $request, $helper, $account_respository, $payment_address_repository, $ledger_entry_repository) {
            $user = $auth->getUser();
            if (!$user) { throw new Exception("User not found", 1); }

            $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

            $resources = $account_respository->findByAddressAndUserID($payment_address['id'], $user['id'], $this->buildAccountFilter($request, $account_respository));

            // get a (valid) type
            $type = null;
            if (strlen($type_string = $request->input('type'))) {
                try {
                    $type = LedgerEntry::typeStringToInteger($type_string);
                } catch (Exception $e) {
                    return $helper->buildJSONResponse(['message' => 'bad type parameter'], 400);
                }
            }

            // add the balances to each one
            $accounts_with_balances = [];
            foreach ($resources as $account) {
                $account_and_balances = $account->serializeForAPI();
                $balances = $ledger_entry_repository->accountBalancesByAsset($account, $type);
                $account_and_balances['balances'] = $balances;
                $accounts_with_balances[] = $account_and_balances;
            }

            return $helper->buildJSONResponse($accounts_with_balances);
        });
    }


    public function transfer($address_uuid, Guard $auth, AccountTransferRequest $request, APIControllerHelper $helper, PaymentAddressRepository $payment_address_repository, APICallRepository $api_call_repository)
    {
        $user = $auth->getUser();
        if (!$user) { throw new Exception("User not found", 1); }

        $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

        $params = $helper->getAttributesFromRequest($request);

        $api_call = $api_call_repository->create([
            'user_id' => $user['id'],
            'details' => [
                'method' => 'api/v1/accounts/transfer/'.$address_uuid,
                'args'   => $params,
            ],
        ]);

        try {
            if (isset($params['close']) AND $params['close']) {
                AccountHandler::close(
                    $payment_address, 
                    $params['from'], $params['to'],
                    $api_call
                );
            } else {
                AccountHandler::transfer(
                    $payment_address, 
                    $params['from'], $params['to'],
                    $params['quantity'], $params['asset'], 
                    isset($params['txid']) ? $params['txid'] : null, 
                    $api_call
                );
            }

            // done
            return new Response('', 204);

        } catch (HttpException $e) {
            return $helper->buildJSONResponse(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }


    ////////////////////////////////////////////////////////////////////////
    

    protected function buildAccountFilter(Request $request, AccountRepository $account_respository) {
        $definition = $account_respository->getSearchFilterDefinition();
        return IndexRequestFilter::createFromRequest($request, $definition);
    }


}
