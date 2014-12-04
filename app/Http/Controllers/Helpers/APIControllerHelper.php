<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Requests\Request;
use App\Repositories\Contracts\APIResourceRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class APIControllerHelper {

    public function __construct() {
        // code
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(APIResourceRepositoryContract $api_resource_repository)
    {
        // all monitors
        $out = [];
        foreach ($api_resource_repository->findAll() as $resource) {
            $out[] = $resource->serializeForAPI();
        }
        return json_encode($out);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(APIResourceRepositoryContract $api_resource_repository, $attributes)
    {
        $new_resource = $api_resource_repository->create($attributes);
        return json_encode($new_resource->serializeForAPI());
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(APIResourceRepositoryContract $api_resource_repository, $id)
    {
        $resource = $api_resource_repository->findByUuid($id);
        if (!$resource) { return new JsonResponse(['message' => 'resource not found'], 404); }

        return json_encode($resource->serializeForAPI());
    }


    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(APIResourceRepositoryContract $api_resource_repository, $id, $attributes)
    {
        $resource = $api_resource_repository->findByUuid($id);
        if (!$resource) { return new JsonResponse(['message' => 'resource not found'], 404); }

        $success = $api_resource_repository->update($resource, $attributes);
        if (!$success) { return new JsonResponse(['message' => 'resource not found'], 404); }

        return json_encode($resource->serializeForAPI());
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(APIResourceRepositoryContract $api_resource_repository, $id)
    {
        $resource = $api_resource_repository->findByUuid($id);
        if (!$resource) { return new JsonResponse(['message' => 'resource not found'], 404); }

        // delete
        $api_resource_repository->delete($resource);

        // return 204
        return new Response('', 204);
    }

    public function getAttributesFromRequest(Request $request) {
        $attributes = [];
        $allowed_vars = array_keys($request->rules());
        $request_vars = $request->all();
        foreach($allowed_vars as $allowed_var_name) {
            if (isset($request_vars[$allowed_var_name])) {
                $attributes[$allowed_var_name] = $request_vars[$allowed_var_name];
            }
        }
        return $attributes;
    }

}
