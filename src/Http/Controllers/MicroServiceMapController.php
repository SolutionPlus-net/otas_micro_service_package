<?php

namespace Otas\MicroService\Http\Controllers;

use Exception;
use Otas\MicroService\Http\Controllers\Controller;
use Otas\MicroService\Http\Requests\MicroServiceMapStoreRequest;

class MicroServiceMapController extends Controller
{
    public function __construct()
    {
        $this->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Otas\MicroService\Http\Requests\RoleStoreRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(MicroServiceMapStoreRequest $request)
    {
        try {
            $request->storeMicroServiceMap();
        } catch (Exception $exception) {
            return response([
                'message' => 'not allowed',
            ], 405);
        }
        return response([
            'message' => 'established',
            'secret' => config('microservice.local_secret'),
        ]);
    }
}
