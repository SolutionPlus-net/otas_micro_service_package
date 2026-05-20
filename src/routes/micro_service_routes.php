<?php

use Illuminate\Support\Facades\Route;
use Otas\MicroService\Http\Controllers\MicroServiceMapController;

Route::group([
    'prefix' => 'api',
    'middleware' => [
        'micro-service-establish-connection',
    ]
], function () {
    Route::apiResource('micro-services', MicroServiceMapController::class, ['only' => ['store']]);
});