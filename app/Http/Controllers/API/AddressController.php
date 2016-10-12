<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\API\Base\APIController;
use App\Models\PaymentAddress;
use BitWasp\BitcoinLib\BitcoinLib;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcSerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Signature\CompactSignatureSerializerInterface;
use BitWasp\Bitcoin\MessageSigner\MessageSigner;
use BitWasp\Bitcoin\Serializer\MessageSigner\SignedMessageSerializer;
use Illuminate\Http\JsonResponse as Response;
use Illuminate\Support\Facades\Log;
use Input, Exception;
use LinusU\Bitcoin\AddressValidator;
use Nbobtc\Bitcoind\Bitcoind;
use Tokenly\CounterpartyAssetInfoCache\Cache;


class AddressController extends APIController {
	
	public function validateAddress($address)
	{
		$output = array();
		try{
			$is_valid = AddressValidator::isValid($address);
		}
		catch(Exception $e){
			$is_valid = false;
		}
		if(!$is_valid){
			$output['result'] = false;
			$output['is_mine'] = false;
		}
		else{
			$output['result'] = true;
			$get = PaymentAddress::where('address', $address)->first();
			if($get){
				$output['is_mine'] = true;
			}
			else{
				$output['is_mine'] = false;
			}
		}
		return new Response($output);
	}
	
	public function verifyMessage($address)
	{
		$input = Input::all();
		$output = array();
		if(!isset($input['message'])){
			$output['error'] = 'Message required';
			$output['result'] = false;
			return new Response($output, 400);
		}
		if(!isset($input['sig'])){
			$output['error'] = 'Address signature required';
			$output['result'] = false;
			return new Response($output, 400);
		}
		$result = false;
		try {
			$address_obj = AddressFactory::fromString($address);
			$signer = new MessageSigner();
			$cs = EcSerializer::getSerializer(CompactSignatureSerializerInterface::class);
			$serializer = new SignedMessageSerializer($cs);

			$built_signature = 
			    "-----BEGIN BITCOIN SIGNED MESSAGE-----"."\n"
			    .$input['message']."\n"
			    ."-----BEGIN SIGNATURE-----"."\n"
			    .$input['sig']."\n"
			    ."-----END BITCOIN SIGNED MESSAGE-----";

			$signed_message_obj = $serializer->parse($built_signature);

			if ($signer->verify($signed_message_obj, $address_obj)) {
				$result = true;
			}
		}
		catch(Exception $e){
			$result = false;
		}
		$output['result'] = $result;
		return new Response($output);
	}
	
	public function signMessage($address)
	{
		$input = Input::all();
		$output = array();
		if(!isset($input['message']) OR trim($input['message']) == ''){
			$output['error'] = 'Message required';
			$output['result'] = false;
			return new Response($output, 400);
		}
		$payment_address = PaymentAddress::where('uuid', $address)->orWhere('address', $address)->first();
		$found = false;
		if(!$payment_address){
			$output['error'] = 'Bitcoin address does not belong to server';
			$output['result'] = false;
			return new Response($output, 400);
		}

		$address_generator = app('Tokenly\BitcoinAddressLib\BitcoinAddressGenerator');
		$priv_key = $address_generator->privateKey($payment_address->private_key_token);
        $signer = new MessageSigner();
		try{
	        $signed_message_obj = $signer->sign($input['message'], $priv_key);
	        $signed_string = base64_encode($signed_message_obj->getCompactSignature()->getBinary());
		}
		catch(Exception $e){
			Log::debug("ERROR: ".$e->getMessage());
			$signed_string = false;
		}
		
		if(!$signed_string){
			$output['error'] = 'Error signing message';
			$output['result'] = false;
			return new Response($output, 500);
		}
		$output['result'] = $signed_string;
		return new Response($output);
	}


}
