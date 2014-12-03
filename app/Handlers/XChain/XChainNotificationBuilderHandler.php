<?php 

namespace App\Handlers\XChain;

use App\Repositories\MonitoredAddressRepository;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Queue\QueueManager;
use Nc\FayeClient\Client as FayeClient;

class XChainNotificationBuilderHandler {

    public function __construct(MonitoredAddressRepository $monitored_address_repository, QueueManager $queue_manager, Log $log) {
        $this->monitored_address_repository = $monitored_address_repository;
        $this->queue_manager      = $queue_manager;
        $this->log                = $log;
    }

    public function pushEvent($tx_event)
    {
        $sources = ($tx_event['sources'] ? $tx_event['sources'] : []);
        $destinations = ($tx_event['destinations'] ? $tx_event['destinations'] : []);

        // get all addresses that we care about
        $found_addresses = $this->monitored_address_repository->findByAddresses(array_unique(array_merge($sources, $destinations)));
        if (!$found_addresses->count()) { return; }

        $this->wlog("begin loop");
        foreach($found_addresses->get() as $found_address) {
            $this->wlog("\$found_address=$found_address");

            // see if this is a receiving or sending event
            $event_type = null;
            switch ($found_address['monitor_type']) {
                case 'send': $event_type = 'send'; break;
                case 'receive': $event_type = 'receive'; break;
            }

            $notification_data = [
                'event'            => $event_type,
                'notifiedAddress'  => $found_address['address'],

                'txid'             => $tx_event['txid'],
                'isCounterpartyTx' => $tx_event['isCounterpartyTx'],
                'quantity'         => $tx_event['quantity'],
                'quantitySat'      => $tx_event['quantitySat'],
                'asset'            => $tx_event['asset'],

                'sources'          => ($tx_event['sources'] ? $tx_event['sources'] : []),
                'destinations'     => ($tx_event['destinations'] ? $tx_event['destinations'] : []),

                'counterpartyTx'   => $tx_event['counterpartyTx'],
                'bitcoinTx'        => $tx_event['bitcoinTx'],

                'timestamp'        => $tx_event['timestamp'],
            ];


            // put notification in the queue
            // Queue::push('notification', $notification_data, 'notifications_out');
            // Queue::pushRaw(['job' => 'notification', 'data' => $notification_data], 'notifications_out');
            // $this->queue_manager->connection('notifications_out')->push('notification', $notification_data);
            $this->queue_manager
                ->connection('notifications_out')
                ->pushRaw(['job' => 'notification', 'data' => $notification_data], 'notifications_out');
        }

    }

    public function subscribe($events) {
        $events->listen('xchain.tx.received', 'App\Handlers\XChain\XChainNotificationBuilderHandler@pushEvent');
    }

    protected function wlog($text) {
        $this->log->info($text);
    }

}
