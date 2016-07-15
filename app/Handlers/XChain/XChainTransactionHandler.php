<?php

namespace App\Handlers\XChain;

use App\Handlers\XChain\Network\Factory\NetworkHandlerFactory;
use App\Models\Block;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHP_Timer;
use Tokenly\LaravelEventLog\Facade\EventLog;
use \Exception;

class XChainTransactionHandler {

    public function __construct(NetworkHandlerFactory $network_handler_factory) {
        $this->network_handler_factory = $network_handler_factory;
    }

    public function handleUnconfirmedTransaction($parsed_tx, $confirmations, $block_seq, $block) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        $transaction = $transaction_handler->storeParsedTransaction($parsed_tx);

        $found_addresses = $transaction_handler->findMonitoredAndPaymentAddressesByParsedTransaction($parsed_tx);
        try {
            $transaction_handler->storeProvisionalTransaction($transaction, $found_addresses);
            $transaction_handler->updateUTXOs($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
            $transaction_handler->updateAccountBalances($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
            $transaction_handler->sendNotifications($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
        } catch (Exception $e) {
            EventLog::logError('handleUnconfirmedTransaction.error', $e, ['id' => $transaction['id'], 'txid' => $transaction['txid'],]);
        }

        return;
    }



    public function handleConfirmedTransaction($parsed_tx, $confirmations, $block_seq, Block $block, $block_event_context=null) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($parsed_tx['network']);

        if ($block_event_context === null) { $block_event_context = $transaction_handler->newBlockEventContext(); }

        $found_addresses = $transaction_handler->findMonitoredAndPaymentAddressesByParsedTransaction($parsed_tx);

        try {
            $transaction_handler->updateProvisionalTransaction($parsed_tx, $found_addresses, $confirmations);
            $transaction_handler->invalidateProvisionalTransactions($found_addresses, $parsed_tx, $confirmations, $block_seq, $block, $block_event_context);
            $transaction_handler->updateUTXOs($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
            $transaction_handler->updateAccountBalances($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
            $transaction_handler->sendNotifications($found_addresses, $parsed_tx, $confirmations, $block_seq, $block);
        } catch (Exception $e) {
            EventLog::logError('handleConfirmedTransaction.error', $e, ['txid' => $parsed_tx['txid'],]);
        }

        return;
    }

    public function handleConfirmedBalanceChange($balance_change_event, $confirmations, Block $block) {
        $transaction_handler = $this->network_handler_factory->buildTransactionHandler($balance_change_event['network']);

        $found_addresses = $transaction_handler->findMonitoredAndPaymentAddressesByBalanceChangeEvent($balance_change_event);

        try {
            $transaction_handler->updateAccountBalancesFromBalanceChangeEvent($found_addresses, $balance_change_event, $confirmations, $block);
            $transaction_handler->sendNotificationsFromBalanceChangeEvent($found_addresses, $balance_change_event, $confirmations, $block);
        } catch (Exception $e) {
            EventLog::logError('handleConfirmedBalanceChange.error', $e, $balance_change_event);
        }

        return;
        /*
        {
            "event": "debit",
            "network": "counterparty",
            "blockheight": 332995,
            "time": 1468451006,
            "asset": "XCP",
            "quantity": 200,
            "quantitySat": 20000000000,
            "address": "1NwzrWxTAfvF5iszezDnhEV6kpMnPedq5L",
            "counterpartyData": {
                "type": "debit",
                "action": "open order",
                "asset": "XCP",
                "quantity": 20000000000,
                "address": "1NwzrWxTAfvF5iszezDnhEV6kpMnPedq5L",
                "event": "2222000000000000000000000000000000000000000000000000000000000000",
                "block_index": 332995
            }
        }
        */
    }

    // ------------------------------------------------------------------------------------

    public function subscribe($events) {
        $events->listen('xchain.tx.received',             'App\Handlers\XChain\XChainTransactionHandler@handleUnconfirmedTransaction');
        $events->listen('xchain.tx.confirmed',            'App\Handlers\XChain\XChainTransactionHandler@handleConfirmedTransaction');
        $events->listen('xchain.balanceChange.confirmed', 'App\Handlers\XChain\XChainTransactionHandler@handleConfirmedBalanceChange');
    }




}
