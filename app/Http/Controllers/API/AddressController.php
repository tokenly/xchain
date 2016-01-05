<?php
namespace App\Http\Controllers\API;
use Input, Exception;
use App\Http\Controllers\API\Base\APIController;
use Illuminate\Http\JsonResponse as Response;
use Illuminate\Support\Facades\Log;
use Tokenly\CounterpartyAssetInfoCache\Cache;
use Nbobtc\Bitcoind\Bitcoind;
use BitWasp\BitcoinLib\BitcoinLib;
use App\Models\PaymentAddress;


class AddressController extends APIController {
	
	public function validateAddress($address)
	{
		$bitcoin = new BitcoinLib;
		$output = array();
		try{
			$validate = $bitcoin->validate_address($address);
		}
		catch(Exception $e){
			$validate = false;
		}
		if(!$validate){
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
		$bitcoin = new BitcoinLib;
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
		try{
			$verify = $bitcoin->verifyMessage($address, $input['sig'], $input['message']);
			if($verify){
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
		$get = PaymentAddress::where('uuid', $address)->orWhere('address', $address)->first();
		$found = false;
		if(!$get){
			$output['error'] = 'Bitcoin address does not belong to server';
			$output['result'] = false;
			return new Response($output, 400);
		}

		$address = $get->address;
		$address_generator = app('Tokenly\BitcoinAddressLib\BitcoinAddressGenerator');
		$lib = new BitcoinLib;
		$priv_key = $address_generator->WIFPrivateKey($get->private_key_token);
		$priv_key = BitcoinLib::WIF_to_private_key($priv_key);
		$sign = $priv_key;

		try{
			$sign = $lib->signMessage($input['message'], $priv_key);
		}
		catch(Exception $e){
			$sign = false;
		}
		
		if(!$sign){
			$output['error'] = 'Error signing message';
			$output['result'] = false;
			return new Response($output, 500);
		}
		$output['result'] = $sign;
		return new Response($output);
	}


}
