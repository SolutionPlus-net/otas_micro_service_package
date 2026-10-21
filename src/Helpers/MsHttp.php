<?php

namespace Otas\MicroService\Helpers;

use Illuminate\Support\Facades\App;
use Otas\MicroService\Actions\SendRequestAction;
use Otas\MicroService\Actions\EstablishConnectionAction;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Services\MicroServiceMapService;

class MsHttp
{
    public static function get(string $microserviceName, string $uri, array $params = [], array $additionalHeaders = [])
    {
        return self::send(method: 'get', microserviceName: $microserviceName, uri: $uri, data: $params, additionalHeaders: $additionalHeaders);
    }

    public static function post(string $microserviceName, string $uri, array $data = [], array $additionalHeaders = [])
    {
        return self::send(method: 'post', microserviceName: $microserviceName, uri: $uri, data: $data, additionalHeaders: $additionalHeaders);
    }

    public static function put(string $microserviceName, string $uri, array $data = [], array $additionalHeaders = [])
    {
        return self::send(method: 'put', microserviceName: $microserviceName, uri: $uri, data: $data, additionalHeaders: $additionalHeaders);
    }

    public static function establish(string $microserviceName, string $origin, int|null $localPort = null)
    {
        return EstablishConnectionAction::handle(microserviceName: $microserviceName, origin: $origin, localPort: $localPort);
    }

    public static function current()
    {
        return request()->currentRequestMs ?? self::testingMicroservice();
    }

    public static function testingMicroservice()
    {
        if (! App::environment(['local', 'testing'])) return null;

        request()->currentRequestMs = MicroServiceMapService::query()->first() ?? MicroServiceMap::factory()->create();

        return request()->currentRequestMs;
    }

    public static function refreshCache(): void
    {
        MicroServiceMapService::refreshCache();
    }

    private static function send(string $method, string $microserviceName, string $uri, array $data, array $additionalHeaders)
    {
        $microService = MicroServiceMapService::findConnectedByName($microserviceName);

        return SendRequestAction::handle(
            method: $method,
            origin: $microService->origin,
            uri: $uri,
            data: $data,
            secretKey: $microService->token->send_secret_key,
            accessKey: $microService->token->send_access_key,
            additionalHeaders: $additionalHeaders,
        );
    }
}
