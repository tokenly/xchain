<?php

namespace App\Console\Commands\Transaction;

use App\Blockchain\Sender\FeePriority;
use App\Models\TXO;
use App\Repositories\PaymentAddressRepository;
use App\Repositories\TXORepository;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Nbobtc\Bitcoind\Bitcoind;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\CurrencyLib\CurrencyUtil;
use Tokenly\LaravelEventLog\Facade\EventLog;

class MultiInputSweepCommand extends Command {

    const SATOSHI = 100000000;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchain:multi-input-sweep';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends a multi-input transaction';


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['destination', InputArgument::REQUIRED, 'A destination'],
            ['addresses', InputArgument::REQUIRED, 'A comma-separated list of addresses or UUIDs'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['fee-rate', 'f', InputOption::VALUE_OPTIONAL, 'Fee rate', 'medlow'],
            ['broadcast', 'b', InputOption::VALUE_NONE, 'Broadcast the transaction if set'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $destination = $this->input->getArgument('destination');
        $addresses_text = $this->input->getArgument('addresses');
        $addresses = preg_split('!\s*,\s*!', $addresses_text);
        $broadcast = !!$this->option('broadcast');
        $fee_rate = $this->option('fee-rate');

        $payment_address_repository = app(PaymentAddressRepository::class);
        $bitcoind = app(Bitcoind::class);

        $address_models = [];
        foreach($addresses as $address) {
            $address_model = $payment_address_repository->findByUuid($address);
            if (!$address_model) {
                $potential_address_models = $payment_address_repository->findByAddress($address)->get();
                foreach($potential_address_models as $potential_address_model) {
                    if ($potential_address_model['private_key_token']) {
                        $address_model = $potential_address_model;
                        break;
                    }
                }
            }
            if (!$address_model) {
                $this->error("Unable to find address model for $address");
                return 1;
            }
            $address_models[] = $address_model;
        }

        // echo "\$address_models: ".json_encode($address_models, 192)."\n";
        $this->comment("Building transaction for ".count($address_models)." ".str_plural('address', count($address_models))." ".collect($address_models)->pluck('address')->implode(","));


        $placeholder_fee = 100000;
        list($signed_transaction, $total_sum) = $this->buildTransaction($address_models, $destination, $placeholder_fee);
        $parsed_tx = app('\TransactionComposerHelper')->parseBTCTransaction($signed_transaction->getHex(), $total_sum);
        // echo "\$parsed_tx: ".json_encode($parsed_tx, 192)."\n";

        // determine the real fee
        $size = $parsed_tx['size'];
        $satoshis_per_byte = app(FeePriority::class)->getSatoshisPerByte($fee_rate);
        $fee = $satoshis_per_byte * $size;

        list($signed_transaction, $total_sum) = $this->buildTransaction($address_models, $destination, $fee);
        $parsed_tx = app('\TransactionComposerHelper')->parseBTCTransaction($signed_transaction->getHex(), $total_sum);
        echo "\$parsed_tx: ".json_encode($parsed_tx, 192)."\n";

        // $this->comment("Total outputs is ".CurrencyUtil::satoshisToValue($total_sum)." BTC");

        $this->comment("Built transaction to send ".CurrencyUtil::satoshisToValue($total_sum)." BTC to {$destination} with fee ".CurrencyUtil::satoshisToFormattedString($fee)." ({$satoshis_per_byte} sat/byte for fee rate {$fee_rate})");

        if ($broadcast) {
            try {
                $sent_tx_id = $bitcoind->sendrawtransaction($signed_transaction->getHex());
                $this->info('Sent transaction with TXID '.$sent_tx_id);
            } catch (Exception $e) {
                // show the exception
                $this->error("Bitcoin daemon error while sending raw transaction: ".ltrim($e->getMessage()." ".$e->getCode()));
            }

        }

        $this->comment('done');
    }

    protected function buildTransaction($address_models, $destination, $fee) {
        $txo_repository    = app(TXORepository::class);
        $address_generator = app(BitcoinAddressGenerator::class);
        $tx_builder        = TransactionFactory::build();

        // build all inputs
        $total_sum = 0;
        $groups_of_txos_to_sign = [];
        foreach($address_models as $payment_address) {
            $utxos = $txo_repository->findByPaymentAddress($payment_address, [TXO::UNCONFIRMED, TXO::CONFIRMED], true)->toArray();
            $inputs_to_sign = $this->addInputsAndReturnPreviousOutputs($utxos, $tx_builder);
            $groups_of_txos_to_sign[] = [
                'payment_address' => $payment_address,
                'inputs_to_sign'  => $inputs_to_sign,
            ];

            $sum = $this->sumOutputs($utxos);
            $total_sum += $sum;
        }

        // sweep to output
        $tx_builder->payToAddress($total_sum - $fee, AddressFactory::fromString($destination));

        // sign
        $transaction = $tx_builder->get();
        $signer = new Signer($transaction, Bitcoin::getEcAdapter());

        // sign each input script
        $all_input_utxos = [];
        $input_offset = 0;
        foreach($groups_of_txos_to_sign as $txo_to_sign_data) {
            $payment_address = $txo_to_sign_data['payment_address'];
            $inputs_to_sign  = $txo_to_sign_data['inputs_to_sign'];
            $private_key     = $address_generator->privateKey($payment_address['private_key_token']);

            foreach($inputs_to_sign as $input_to_sign) {
                $signer->sign($input_offset, $private_key, $input_to_sign);
                $all_input_utxos[] = $input_to_sign;
                ++$input_offset;
            }
        }

        return [$signer->get(), $total_sum];
    }

    protected function sumOutputs($utxos) {
        $sum = 0;
        foreach($utxos as $utxo) {
            $sum += $utxo['amount'];
        }
        return $sum;
    }

    protected function addInputsAndReturnPreviousOutputs($utxos, $tx_builder) {
        $prev_transaction_outputs = [];
        foreach($utxos as $utxo) {
            $script_instance = ScriptFactory::fromHex($utxo['script']);
            $tx_builder->input($utxo['txid'], $utxo['n'], $script_instance);

            $prev_transaction_output = new TransactionOutput($utxo['amount'] / self::SATOSHI, $script_instance);
            $prev_transaction_outputs[] = $prev_transaction_output;
        }
        return $prev_transaction_outputs;
    }


}
