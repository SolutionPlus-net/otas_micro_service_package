<?php

use Illuminate\Support\Facades\Route;
use Otas\MicroService\Http\Middleware\MicroServiceMiddleware;
use Otas\MicroService\Http\Controllers\MicroServiceMapController;
use Otas\MicroService\Http\Controllers\MicroServiceKeysController;

Route::group([
    'prefix' => 'api',
    'middleware' => [
        'micro-service-establish-connection',
    ]
], function () {
    Route::apiResource('micro-services', MicroServiceMapController::class, ['only' => ['store']]);
});

Route::group([
    'prefix' => 'api',
    'middleware' => [
        MicroServiceMiddleware::class,
    ]
], function () {
    Route::post('micro-services/renew-keys', [MicroServiceKeysController::class, 'renew']);
});
