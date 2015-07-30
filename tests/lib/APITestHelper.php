<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Tokenly\HmacAuth\Generator;
use \PHPUnit_Framework_Assert as PHPUnit;

class APITestHelper  {

    protected $override_user = null;
    protected $repository    = null;

    function __construct(Application $app) {
        $this->app = $app;
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // setups

    public function createModelWith($create_model_fn) {
        $this->create_model_fn = $create_model_fn;
        return $this;
    }
    public function useRepository($repository) {
        $this->repository = $repository;
        return $this;
    }

    public function useUserHelper($user_helper) {
        $this->user_helper = $user_helper;
        return $this;
    }

    public function useCleanupFunction($cleanup_fn) {
        $this->cleanup_fn = $cleanup_fn;
        return $this;
    }

    public function setURLBase($url_base) {
        $this->url_base = $url_base;
        return $this;
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    

    public function cleanup() {
        if (isset($this->cleanup_fn) AND is_callable($this->cleanup_fn)) {
            call_user_func($this->cleanup_fn, $this->repository);
        } else {
            if ($this->repository) {
                foreach($this->repository->findAll() as $model) {
                    $this->repository->delete($model);
                }
            }
        }
        return $this;
    }

    public function testRequiresUser($url_extension=null) {
        $this->cleanup();

        // create a model
        $created_model = $this->newModel();

        return $this->testURLCallRequiresUser($this->extendURL($this->url_base, $this->extendURL('/'.$created_model['uuid'], $url_extension)));
    }

    public function testURLCallRequiresUser($url) {
        // call the API without a user
        $request = $this->createAPIRequest('GET', $url);
        $response = $this->sendRequest($request);
        PHPUnit::assertEquals(403, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()." for GET ".$url);
    }

    public function testCreate($create_vars) {
        $this->cleanup();

        // call the API
        $url = $this->extendURL($this->url_base, null);
        $response = $this->callAPIWithAuthentication('POST', $url, $create_vars);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()."\nfor POST ".$url."\n".(json_encode(json_decode($response->getContent()), 192)));
        $actual_response_from_api = json_decode($response->getContent(), true);
        PHPUnit::assertNotEmpty($actual_response_from_api);


        // load from repository
        $loaded_resource_model = $this->repository->findByUuid($actual_response_from_api['id']);
        PHPUnit::assertNotEmpty($loaded_resource_model);

        // build expected response from API
        $expected_response_from_api = $loaded_resource_model->serializeForAPI();
        PHPUnit::assertEquals($expected_response_from_api, $actual_response_from_api);

        return $loaded_resource_model;
    }

    public function testIndex($url_extension=null, $sort_ascending=true, $create_noise_fn=null) {
        $this->cleanup();

        // create 2 models
        $created_models = [];
        $created_models[] = $this->newModel();
        $created_models[] = $this->newModel();

        // create some other models
        if ($create_noise_fn) { call_user_func($create_noise_fn, $created_models); }
        
        // reverse order if needed
        if (!$sort_ascending) { $created_models = array_reverse($created_models); }

        // now call the API
        $url = $this->extendURL($this->url_base, $url_extension);
        $response = $this->callAPIWithAuthentication('GET', $url);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()." for GET ".$url);
        $actual_response_from_api = json_decode($response->getContent(), true);
        PHPUnit::assertNotEmpty($actual_response_from_api);

        // populate the $expected_created_resource
        $expected_api_response = [$created_models[0]->serializeForAPI(), $created_models[1]->serializeForAPI()];
        $expected_api_response = $this->normalizeExpectedAPIResponse($expected_api_response, $actual_response_from_api);

        // check response
        PHPUnit::assertEquals($expected_api_response, $actual_response_from_api);

        // return the models
        return $created_models;
    }

    public function testPublicIndex($public_url_base, $url_extension, $sort_ascending=true) {
        $this->cleanup();

        // create 2 models
        $created_models = [];
        $created_models[] = $this->newModel();
        $created_models[] = $this->newModel();

        // reverse order if needed
        if (!$sort_ascending) { $created_models = array_reverse($created_models); }
        
        // now call the API
        $url = $this->extendURL(rtrim($public_url_base, '/'), '/'.$url_extension);
        $response = $this->callAPIWithoutAuthentication('GET', $url);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()." for GET ".$url);
        $actual_response_from_api = json_decode($response->getContent(), true);
        PHPUnit::assertNotEmpty($actual_response_from_api);

        // populate the $expected_created_resource
        $expected_api_response = [$created_models[0]->serializeForAPI(), $created_models[1]->serializeForAPI()];
        $expected_created_resource = $this->normalizeExpectedAPIResponse($expected_api_response, $actual_response_from_api);

        // check response
        PHPUnit::assertEquals($expected_created_resource, $actual_response_from_api);

        // return the models
        return $created_models;
    }

    public function testShow() {
        $this->cleanup();

        // create a model
        $created_model = $this->newModel();

        // call the API
        $url = $this->extendURL($this->url_base, '/'.$created_model['uuid']);
        $response = $this->callAPIWithAuthentication('GET', $url);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()." for GET ".$url);
        $actual_response_from_api = json_decode($response->getContent(), true);
        PHPUnit::assertNotEmpty($actual_response_from_api);

        // populate the $expected_created_resource
        $expected_api_response = $created_model->serializeForAPI();
        $expected_api_response = $this->normalizeExpectedAPIResponse($expected_api_response, $actual_response_from_api);

        // check response
        PHPUnit::assertEquals($expected_api_response, $actual_response_from_api);

        // return the model
        return $created_model;
    }

    public function testPublicShow($public_url_base) {
        $this->cleanup();

        // create a model
        $created_model = $this->newModel();

        // call the API
        $url = $this->extendURL(rtrim($public_url_base, '/'), '/'.$created_model['uuid']);
        $response = $this->callAPIWithoutAuthentication('GET', $url);
        PHPUnit::assertEquals(200, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()." for GET ".$url);
        $actual_response_from_api = json_decode($response->getContent(), true);
        PHPUnit::assertNotEmpty($actual_response_from_api);

        // populate the $expected_created_resource
        $expected_api_response = $created_model->serializeForAPI('public');
        $expected_api_response = $this->normalizeExpectedAPIResponse($expected_api_response, $actual_response_from_api);

        // check response
        PHPUnit::assertEquals($expected_api_response, $actual_response_from_api);

        // return the model
        return $created_model;
    }

    public function testUpdate($update_attributes) {
        $this->cleanup();

        // create a model
        $created_model = $this->newModel();

        // call the API
        $url = $this->extendURL($this->url_base, '/'.$created_model['uuid']);
        $response = $this->callAPIWithAuthentication('PUT', $url, $update_attributes);
        if ($response->getStatusCode() != 200 AND $response->getStatusCode() != 204) {
            throw new Exception("Unexpected response code of ".$response->getStatusCode()." for GET ".$url."\n".(json_encode(json_decode($response->getContent()), 192)), 1);
        }

        // load the model and make sure it was updated
        $reloaded_model = $this->repository->findByUuid($created_model['uuid']);


        // only check the updated attributes
        $expected_model_vars = [];
        foreach(array_keys($update_attributes) as $k) {
            $v = $update_attributes[$k];
            $decoded = (substr($v,0,1) == '{' OR substr($v,0,1) == '[') ? json_decode($v,1) : null;
            if ($decoded !== null) {
                $expected_model_vars[$k] = $decoded;
            } else {
                $expected_model_vars[$k] = $update_attributes[$k];
            }
        }
        $actual_model_vars = [];
        foreach(array_keys($update_attributes) as $k) {$actual_model_vars[$k] = $reloaded_model[$k]; }
        PHPUnit::assertEquals($expected_model_vars, $actual_model_vars);

        // return the model
        return $reloaded_model;
    }

    public function testDelete() {
        $this->cleanup();

        // create a model
        $created_model = $this->newModel();

        // call the API
        $url = $this->extendURL($this->url_base, '/'.$created_model['uuid']);
        $response = $this->callAPIWithAuthentication('DELETE', $url);
        PHPUnit::assertEquals(204, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()." for GET ".$url);

        // make sure the model was deleted
        $reloaded_model = $this->repository->findByUuid($created_model['uuid']);
        PHPUnit::assertEmpty($reloaded_model);

        // return the delete model
        return $created_model;
    }


    public function be(User $new_user) {
        $this->override_user = $new_user;
        return $this;
    }

    public function getUser() {
        if (isset($this->override_user)) { return $this->override_user; }
        return $this->user_helper->getSampleUser();
    }

    public function callAPIAndValidateResponse($method, $url, $parameters=[], $expected_response_code=200, $with_authentication=true) {
        if ($with_authentication) {
            $response = $this->callAPIWithAuthentication($method, $url, $parameters);
        } else {
            $response = $this->callAPIWithoutAuthentication($method, $url, $parameters);
        }
        PHPUnit::assertEquals($expected_response_code, $response->getStatusCode(), "Unexpected response code of ".$response->getStatusCode()."\n\nfor {$method} {$url}".(($actual_response_from_api = json_decode($response->getContent(), true)) ? json_encode($actual_response_from_api, 192) : null));
        $actual_response_from_api = json_decode($response->getContent(), true);
        if ($expected_response_code != 204) {
            PHPUnit::assertNotEmpty($actual_response_from_api);
        }
        return $actual_response_from_api;
    }

    public function callAPIWithoutAuthenticationAndValidateResponse($method, $url, $parameters=[], $expected_response_code=200) {
        return $this->callAPIAndValidateResponse($method, $url, $parameters, $expected_response_code, false);
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////

    public function callAPIWithAuthentication($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null) {
        $request = $this->createAPIRequest($method, $uri, $parameters, $cookies, $files, $server, $content);
        $generator = new Generator();
        $user = $this->getUser();
        $api_token = $user['apitoken'];
        $secret    = $user['apisecretkey'];
        $generator->addSignatureToSymfonyRequest($request, $api_token, $secret);
        return $this->sendRequest($request);
    }

    public function callAPIWithoutAuthentication($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null) {
        $request = $this->createAPIRequest($method, $uri, $parameters, $cookies, $files, $server, $content);
        return $this->sendRequest($request);
    }

    public function createAPIRequest($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null) {
        // convert a POST to json
        if ($parameters AND in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $content = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
            $server['CONTENT_TYPE'] = 'application/json';
            $parameters = [];
        }

        return Request::create($uri, $method, $parameters, $cookies, $files, $server, $content);
    }

    public function sendRequest($request) {
        return $this->app->make('Illuminate\Contracts\Http\Kernel')->handle($request);
    }

    protected function extendURL($base_url, $url_extension) {
        if (!strlen($url_extension)) { return $base_url; }
        return $base_url.(strlen($url_extension) ? '/'.ltrim($url_extension, '/') : '');
    }

    protected function normalizeExpectedAPIResponse($expected_api_response, $actual_response_from_api) {
        return $expected_api_response;
    }


    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    
    

    public function newModel() {
        $model = call_user_func($this->create_model_fn, $this->getUser());
        if (!$model) { throw new Exception("Failed to create model", 1); }
        return $model;
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    
    public function testUpdateErrors($created_resource, $error_scenarios)
    {
        return $this->testErrors($error_scenarios, [
            'method'  => 'PATCH',
            'urlPath' => '/'.$created_resource['uuid'],
        ]);
    }

    public function testAddErrors($error_scenarios, $url_path = '')
    {
        return $this->testErrors($error_scenarios, [
            'method'  => 'POST',
            'urlPath' => $url_path,
        ]);
    }

    public function testErrors($error_scenarios, $defaults=[])
    {
        foreach($error_scenarios as $error_scenario) {
            $expected_error_code = isset($error_scenario['expectedErrorCode']) ? $error_scenario['expectedErrorCode'] : null;
            $this->runErrorScenario(isset($error_scenario['method']) ? $error_scenario['method'] : $defaults['method'], isset($error_scenario['urlPath']) ? $error_scenario['urlPath'] : $defaults['urlPath'], $error_scenario['postVars'], $error_scenario['expectedErrorString'], $expected_error_code);
        }
    }

    protected function runErrorScenario($method, $url_path, $posted_vars, $expected_error_string, $expected_error_code=null) {
        $response = $this->callAPIWithAuthentication($method, $this->url_base.$url_path, $posted_vars);
        if ($expected_error_code === null) { $expected_error_code = 400; }
        PHPUnit::assertEquals($expected_error_code, $response->getStatusCode(), "Unexpected status code of ".$response->getStatusCode().".  Response was: ".$response->getContent());
        $response_data = json_decode($response->getContent(), true);
        PHPUnit::assertContains($expected_error_string, isset($response_data['errors']) ? $response_data['errors'][0] : $response_data['message']);

    }


}
