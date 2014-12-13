<?php 

namespace App\Http\Controllers\API;

use App\Blockchain\Block\ConfirmationsBuilder;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\APIControllerHelper;
use App\Repositories\MonitoredAddressRepository;
use App\Repositories\NotificationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller {

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(MonitoredAddressRepository $monitored_address_repository, NotificationRepository $notification_respository, ConfirmationsBuilder $confirmations_builder, $addressId)
    {
        $monitored_address = $monitored_address_repository->findByUuid($addressId);
        if (!$monitored_address) {
            if (!$monitored_address) { return new JsonResponse(['message' => 'resource not found'], 404); }
        }

        // use the last notification for each txid
        $latest_notifications_by_txid = [];
        foreach ($notification_respository->findByMonitoredAddressId($monitored_address['id']) as $notification_model) {
            $notification = $notification_model['notification'];
            $key = $notification['txid'].'-'.$notification['event'];
            if (isset($latest_notifications_by_txid[$key])) {
                if ($notification['confirmations'] > $latest_notifications_by_txid[$key]['confirmations']) {
                    $latest_notifications_by_txid[$key] = $notification;
                }
            } else {
                $latest_notifications_by_txid[$key] = $notification;
            }
        }


        $out = [];
        $current_block_height = $confirmations_builder->findLatestBlockHeight();
        foreach($latest_notifications_by_txid as $raw_notification) {
            $notification = $raw_notification;

            // update the number of confirmations by block hash
            if (isset($raw_notification['bitcoinTx']['blockhash'])) {
                $confirmations = $confirmations_builder->getConfirmationsForBlockHashAsOfHeight($raw_notification['bitcoinTx']['blockhash'], $current_block_height);
            } else {
                $confirmations = 0;
            }

            $notification['confirmations'] = $confirmations;
            $notification['confirmed'] = ($confirmations > 0 ? true : false);
            $out[] = $notification;
        }

        return json_encode($out);
    }


}
