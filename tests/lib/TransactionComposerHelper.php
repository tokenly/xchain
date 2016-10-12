<?php

use App\Models\TXO;
use App\Repositories\TXORepository;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Address\ScriptHashAddress;
use BitWasp\Bitcoin\Key\PublicKeyFactory;
use BitWasp\Bitcoin\Script\Classifier\InputClassifier;
use BitWasp\Bitcoin\Script\Classifier\OutputClassifier;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;

/**
*  TransactionComposerHelper
*/
class TransactionComposerHelper
{

    public function __construct() {
    }


    public function parseCounterpartyTransaction($raw_transaction_hex) {
        return $this->parseBTCTransaction($raw_transaction_hex, true);
    }

    public function parseBTCTransaction($raw_transaction_hex, $is_counterparty=false) {
        if (!$raw_transaction_hex) { throw new Exception("Transaction hex was empty", 1); }

        $transaction = TransactionFactory::fromHex($raw_transaction_hex);

        $out = [];

        // inputs
        $inputs = $transaction->getInputs();
        $out['inputs'] = [];
        foreach($inputs as $input) {

            // build the ASM
            $script = $input->getScript();
            $asm = "";
            $opcodes = $script->getOpCodes();
            foreach($script->getScriptParser()->decode() as $op) {
                // echo "\$op: ".get_class($op)."\n";
                // $asm .= $opcodes->getOp($op->getOp());
                if ($op->isPush()) {
                    $item = $op->getData()->getHex();
                } else {
                    $item = $opcodes->getOp($op->getOp());
                }
                $asm = ltrim($asm." ".$item);
            }

            // extract the address
            $address = null;

            // address decoding not implemented yet
            // $classifier = new InputClassifier($script);
            // if ($classifier->isPayToPublicKeyHash()) {
            //     $decoded = $script->getScriptParser()->decode();
            //     $hex_buffer = $decoded[1]->getData();
            //     $public_key = PublicKeyFactory::fromHex($hex_buffer);
            //     $address = $public_key->getAddress()->getAddress();
            // } else if ($classifier->isPayToScriptHash()) {
            //     $decoded = $script->getScriptParser()->decode();
            //     $hex_buffer = $decoded[count($decoded)-1]->getData();
            //     $script = ScriptFactory::fromHex($hex_buffer);
            //     $sh_address = new ScriptHashAddress($script->getScriptHash());
            //     $address = $sh_address->getAddress();
            // }

            $outpoint = $input->getOutPoint();
            $out['inputs'][] = ['txid' => $outpoint->getTxId()->getHex(), 'n' => $outpoint->getVout(), 'script' => $script->getHex(), 'asm' => $asm, /* 'addr' => $address, */];

        }


        // txid
        $out['txid'] = $transaction->getTxId()->getHex();

        // destination
        $output_offset = 0;
        $outputs = $transaction->getOutputs();
        $destination = AddressFactory::getAssociatedAddress($outputs[$output_offset]->getScript());
        $out['destination'] = $destination->getAddress();
        $out['btc_amount'] = $outputs[0]->getValue();

        if ($is_counterparty) {
            // OP_RETURN
            ++$output_offset;
            $obfuscated_op_return_hex = $outputs[$output_offset]->getScript()->getScriptParser()->decode()[1]->getData()->getHex();
            $hex = $this->arc4decrypt($transaction->getInput(0)->getOutPoint()->getTxId()->getHex(), $obfuscated_op_return_hex);
            $counterparty_data = $this->parseTransactionData(hex2bin(substr($hex, 16)));
            $out = $counterparty_data + $out;
            $out['btc_dust_size'] = $outputs[0]->getValue();
        }

        // change
        ++$output_offset;
        $change = [];
        for ($i=$output_offset; $i < count($outputs); $i++) { 
            $script = $outputs[$i]->getScript();
            $classifier = new OutputClassifier();
            $script_type = $classifier->classify($script);
            if ($script_type == OutputClassifier::PAYTOPUBKEYHASH) {
                $output = $outputs[$i];
                $change[] = [AddressFactory::getAssociatedAddress($script)->getAddress(), $output->getValue()];
            }
        }
        $out['change'] = $change;

        // sum all outputs
        $sum_out = 0;
        foreach($outputs as $output) {
            $sum_out += $output->getValue();
        }
        $out['sum_out'] = $sum_out;

        return $out;
    }


    // ------------------------------------------------------------------------
    
    protected function arc4decrypt($key, $encrypted_text)
    {
        $init_vector = '';
        return bin2hex(mcrypt_decrypt(MCRYPT_ARCFOUR, hex2bin($key), hex2bin($encrypted_text), MCRYPT_MODE_STREAM, $init_vector));
    }

    protected function parseTransactionData($binary_data) {
        list($type_id, $asset_id_hi, $asset_id_lo, $quantity_hi, $quantity_lo) = array_values(unpack('I1t/N4aq', $binary_data));
        $asset_id_gmp = (gmp_init($asset_id_hi) << 32) | gmp_init($asset_id_lo);
        $quantity_gmp = (gmp_init($quantity_hi) << 32) | gmp_init($quantity_lo);

        $parsed_data = [
            'type' => 'unknown',
        ];
        if ($type_id !== 0) {
            return $parsed_data;
        }

        $parsed_data['type'] = 'send';
        $parsed_data['quantity'] = gmp_strval($quantity_gmp);
        $parsed_data['asset'] = $this->assetIdToName($asset_id_gmp);
        return $parsed_data;
    }

    protected function assetIdToName(GMP $asset_id) {
        if (gmp_cmp($asset_id, 0) === 0) { return 'BTC'; }
        if (gmp_cmp($asset_id, 1) === 0) { return 'XCP'; }

        $b26_digits = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        if ($asset_id < pow(26, 3)) { throw new Exception("asset ID was too low", 1); }

        // look for numeric asset name
        if (gmp_cmp($asset_id, gmp_add(gmp_pow(26, 12), 1)) >= 0) {
            return 'A'.$asset_id;
        }

        # Divide that integer into Base 26 string.
        $asset_name = '';
        $n = clone $asset_id;
        while (gmp_cmp($n, 0) > 0) {
            list($n, $r) = gmp_div_qr($n, 26, GMP_ROUND_ZERO);
            $asset_name = substr($b26_digits, gmp_intval($r), 1).$asset_name;
        }
        return $asset_name;
    }


}