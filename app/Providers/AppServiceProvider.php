<?php namespace App\Providers;

use App\Handlers\XChain\Error\XChainErrorCounter;
use Tokenly\AssetNameUtils\Validator as AssetValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Tokenly\TokenGenerator\TokenGenerator;

class AppServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap any application services.
	 *
	 * @return void
	 */
	public function boot()
	{
        Validator::extend('fee_priority', function($attribute, $value, $parameters, $validator) {
            return app('App\Blockchain\Sender\FeePriority')->isValid($value);
        });

        Validator::extend('asset', function($attribute, $value, $parameters, $validator) {
            if (isset($parameters[0]) AND $parameters[0] == 'multi') {
                return AssetValidator::isValidAssetNames(splitAssetNames($value));
            }
            return AssetValidator::isValidAssetName($value);
        });
	}

	/**
	 * Register any application services.
	 *
	 * This service provider is a great spot to register your various container
	 * bindings with the application. As you can see, we are registering our
	 * "Registrar" implementation here. You can add your own bindings too!
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app->bind(
			'Illuminate\Contracts\Auth\Registrar',
			'App\Services\Registrar'
		);

        $this->app->singleton('XChainErrorCounter', function ($app) {
            return new XChainErrorCounter(env('MAX_ADDRESS_PARSE_ERRORS', 250));
        });

        $token_generator = new TokenGenerator();
        $this->app->instance('Tokenly\TokenGenerator\TokenGenerator', $token_generator);

	}

}
