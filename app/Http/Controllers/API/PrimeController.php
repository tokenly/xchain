<?php 

namespace App\Http\Controllers\API;

use App\Blockchain\Sender\PaymentAddressSender;
use App\Blockchain\Sender\TXOChooser;
use App\Http\Controllers\API\Base\APIController;
use App\Models\TXO;
use App\Repositories\AccountRepository;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\TXORepository;
use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelApiProvider\Filter\IndexRequestFilter;
use Tokenly\LaravelApiProvider\Helpers\APIControllerHelper;
use Tokenly\LaravelEventLog\Facade\EventLog;

class PrimeController extends APIController {

    use DispatchesJobs;

    /**
     * gets a list of the current UTXOs that are primed
     *
     * @return Response
     */
    public function getPrimedUTXOs($address_uuid, Guard $auth, Request $request, APIControllerHelper $helper, PaymentAddressRepository $payment_address_repository, TXORepository $txo_repository)
    {
        try {
            
            $user = $auth->getUser();
            if (!$user) { throw new Exception("User not found", 1); }

            $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

            $size = floatval($request->query('size'));
            if ($size <= 0) { throw new Exception("Invalid size", 400); }
            $size_satoshis = CurrencyUtil::valueToSatoshis($size);

            // get the UTXOs
            //   [TXO::UNCONFIRMED, TXO::CONFIRMED]
            $txos = $this->filterGreenOrConfirmedUTXOs($txo_repository->findByPaymentAddress($payment_address, null, true));

            // count the number that match the size
            $matching_count = 0;
            $total_count = 0;
            $filtered_txos = [];
            foreach($txos as $txo) {
                if ($txo['amount'] == $size_satoshis) {
                    ++$matching_count;
                }

                $filtered_txos[] = [
                    'txid'   => $txo['txid'],
                    'n'      => $txo['n'],
                    'amount' => CurrencyUtil::satoshisToValue($txo['amount']),
                    'type'   => TXO::typeIntegerToString($txo['type']),
                    'green'  => !!$txo['type'],
                ];

                ++$total_count;
            }

            $output = [
                'primedCount' => $matching_count,
                'totalCount'  => $total_count,
                'utxos'       => $filtered_txos,
            ];

            return $helper->buildJSONResponse($output);

        } catch (Exception $e) {
            if ($e->getCode() >= 400 AND $e->getCode() < 500) {
                throw new HttpResponseException(new JsonResponse(['errors' => [$e->getMessage()]], 400));
            }
            throw $e;
        }

    }

    /**
     * Prime the address with UTXOs of a certain size
     *   only if needed
     * 
     *
     * @return Response
     */
    public function primeAddress($address_uuid, Guard $auth, Request $request, APIControllerHelper $helper, PaymentAddressRepository $payment_address_repository, TXORepository $txo_repository, TXOChooser $txo_chooser, PaymentAddressSender $payment_address_sender)
    {
        try {
            
            $user = $auth->getUser();
            if (!$user) { throw new Exception("User not found", 1); }

            $payment_address = $helper->requireResourceOwnedByUser($address_uuid, $user, $payment_address_repository);

            $size = floatval($request->input('size'));
            if ($size <= 0) { throw new Exception("Invalid size", 400); }
            $size_satoshis = CurrencyUtil::valueToSatoshis($size);

            $desired_prime_count = floatval($request->input('count'));
            if ($desired_prime_count <= 0) { throw new Exception("Invalid count", 400); }

            $fee = $request->input('fee');
            if ($fee !== null) {
                $fee = floatval($fee);
                if ($fee <= 0) { throw new Exception("Invalid fee", 400); }
            }

            // get the UTXOs
            //   [TXO::UNCONFIRMED, TXO::CONFIRMED]
            $txos = $this->filterGreenOrConfirmedUTXOs($txo_repository->findByPaymentAddress($payment_address, null, true));

            // count the number that match the size
            $current_primed_count = 0;
            foreach($txos as $txo) {
                if ($txo['amount'] == $size_satoshis) {
                    ++$current_primed_count;
                }
            }

            $txid = null;
            $new_primed_count = $current_primed_count;

            if ($desired_prime_count > $current_primed_count) {
                // create a new priming transaction...
                $desired_new_primes_count_to_create = $desired_prime_count - $current_primed_count;
                $actual_new_primes_count_to_create = $this->findMaximumNewPrimeCountTXOs($txo_chooser, $payment_address, $desired_new_primes_count_to_create, $size, $fee);
                if ($actual_new_primes_count_to_create > 0) {
                    $txid = $this->sendPrimingTransaction($payment_address_sender, $payment_address, $size, $actual_new_primes_count_to_create, $fee);
                    $new_primed_count = $current_primed_count + $actual_new_primes_count_to_create;
                }
            }



            $output = [
                'oldPrimedCount' => $current_primed_count,
                'newPrimedCount' => $new_primed_count,
                'txid'           => $txid,
                'primed'         => ($txid ? true : false),
            ];

            return $helper->buildJSONResponse($output);

        } catch (Exception $e) {
            if ($e->getCode() >= 400 AND $e->getCode() < 500) {
                throw new HttpResponseException(new JsonResponse(['errors' => [$e->getMessage()]], 400));
            }
            throw $e;
        }

    }

    ////////////////////////////////////////////////////////////////////////

    protected function findMaximumNewPrimeCountTXOs($txo_chooser, $payment_address, $desired_prime_count, $float_prime_size, $float_fee) {
        $actual_prime_count = $desired_prime_count;
        while ($actual_prime_count > 0) {
            $float_quantity = $actual_prime_count * $float_prime_size;
            $chosen_txos = $txo_chooser->chooseUTXOsForPriming($payment_address, $float_quantity, $float_fee, null, $float_prime_size);
            if ($chosen_txos) {
                return $actual_prime_count;
            }
            
            --$actual_prime_count;
        }

        return 0;
    }

    protected function sendPrimingTransaction($payment_address_sender, $payment_address, $float_size, $count, $float_fee) {
        $float_quantity = 0.0;
        $bitcoin_address = $payment_address['address'];

        $destinations_collection = [];
        for ($i=0; $i < $count; $i++) { 
            $destinations_collection[] = [$bitcoin_address, $float_size];
            $float_quantity += $float_size;
        }

        return $payment_address_sender->send($payment_address, $destinations_collection, $float_quantity, 'BTC', $float_fee);
    }
    
    // returns only green or confirmed TXOs
    protected function filterGreenOrConfirmedUTXOs($raw_txos) {
        $filtered_txos = [];
        foreach($raw_txos as $raw_txo) {
            if ($raw_txo['type'] == TXO::CONFIRMED OR $raw_txo['green']) {
                $filtered_txos[] = $raw_txo;
            }
        }
        return $filtered_txos;
    }


}
