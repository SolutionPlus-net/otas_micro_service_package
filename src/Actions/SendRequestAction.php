<?php

namespace Otas\MicroService\Actions;

use Illuminate\Support\Facades\Http;
use Otas\MicroService\Helpers\Origin;
use Otas\MicroService\Helpers\RequestEncryption;

class SendRequestAction
{
    public static function handle(
        string $method,
        string $origin,
        string $uri,
        array $data,
        string $secretKey,
        string|null $accessKey = null,
        array $additionalHeaders = []
    ) {
        return Http::withHeaders([...self::headers($method, $accessKey), ...$additionalHeaders])
            ->$method(
                self::protocol() . "{$origin}/api/{$uri}",
                RequestEncryption::encrypt(data: $data, key: $secretKey),
            );
    }

    private static function headers(string $method, string|null $accessKey): array
    {
        $headers = [
            'origin' => Origin::current(),
            'Accept' => 'application/json',
            'Authorization' => 'application/json',
        ];

        if ($method == 'put') {
            $headers['_method'] = 'put';
        }

        if ($accessKey) {
            $headers[RequestEncryption::ACCESS_KEY_HEADER] = $accessKey;
        }

        return $headers;
    }

    private static function protocol(): string
    {
        return config('microservice.secure_requests_only') == 'true' ? 'https://' : 'http://';
    }
}
