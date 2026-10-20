<?php

namespace Otas\MicroService\Helpers;

use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Otas\MicroService\Models\MicroServiceMap;

class MsHttp
{
    public $headers;
    public $protocol;
    public $microservice;
    public $microserviceName;

    protected function __construct(string $microserviceName, string $origin = '')
    {
        $this->microserviceName = $microserviceName;
        $this->microservice = $origin == '' ? $this->destinationMicroService($microserviceName) : new MicroServiceMap([
            'name' => \strtolower($microserviceName),
            'display_name' => $microserviceName,
            'origin' => $origin,
        ]);
        $this->protocol = $this->protocol();
    }

    protected static function destinationMicroService(string $microserviceName)
    {
        $microService = self::firstMsBy('name', $microserviceName);

        if (! $microService) abort(503, 'the service you try to communicate with is not exist yet in linked services list');

        if (! $microService->origin) abort(503, 'can\'t find the selected service origin');

        if (! $microService->destination_key) abort(503, 'can\'t find the selected service key');

        return $microService;
    }

    protected function protocol()
    {
        return config('microservice.secure_requests_only') == 'true' ? 'https://' : 'http://';
    }

    public static function get(
        string $microserviceName,
        string $uri,
        array $params = [],
        array $additionalHeaders = []
    ) {
        return self::send(
            method: 'get',
            microserviceName: $microserviceName,
            uri: $uri,
            data: $params,
            additionalHeaders: $additionalHeaders
        );
    }

    public static function post(
        string $microserviceName,
        string $uri,
        array $data = [],
        array $additionalHeaders = []
    ) {
        return self::send(
            method: 'post',
            microserviceName: $microserviceName,
            uri: $uri,
            data: $data,
            additionalHeaders: $additionalHeaders
        );
    }

    public static function put(
        string $microserviceName,
        string $uri,
        array $data = [],
        array $additionalHeaders = []
    ) {
        return self::send(
            method: 'put',
            microserviceName: $microserviceName,
            uri: $uri,
            data: $data,
            additionalHeaders: $additionalHeaders
        );
    }

    public static function establish(string $microserviceName, string $origin, int|null $localPort = null)
    {
        if (self::firstMsBy('name', $microserviceName)) abort(503, 'service name already exists');
        if (config('microservice.micro_service_name') == '') abort(503, 'current service name is not set yet in "microservice" config file');
        if (config('microservice.project_secret') == '') abort(503, 'project secret is not set yet in "microservice" config file');
        $data = [
            'name' => config('microservice.micro_service_name'),
            'origin' => self::origin(localPort: $localPort),
            'secret' => config('microservice.local_secret'),
        ];
        $response = self::send(
            method: 'post',
            microserviceName: $microserviceName,
            uri: 'micro-services',
            data: $data,
            origin: $origin
        );

        if (isset(((object) $response->json())->secret)) {
            self::saveMicroservice($microserviceName, $origin, ((object) $response->json())->secret);
        }
        return $response;
    }

    protected static function send(
        string $method,
        string $microserviceName,
        string $uri,
        array $data = [],
        string $origin = '',
        array $additionalHeaders = []
    ) {
        $http = new self($microserviceName, $origin);
        $establish = $origin != '';
        return Http::withHeaders($http->headers($method, $additionalHeaders))
            ->$method(
                "{$http->protocol}{$http->microservice->origin}/api/{$uri}",
                $http->encodeRequestBody($data, $establish)
            );
    }

    protected static function firstMsBy(string $columnName, string|int $value)
    {
        return self::cache()?->where($columnName, $value)?->first() ?? self::cache(true)?->where($columnName, $value)?->first();
    }

    // Runs on the package connection without changing the app's default connection (e.g. a tenant database).
    protected static function query()
    {
        return MicroServiceMap::on(config('microservice.db_connection_name') ?: null);
    }

    public static function saveMicroservice(string $microserviceName, string $origin, string $destinationKey)
    {
        $microService = self::query()->updateOrCreate(
            [
                'origin' => $origin,
                'name' => $microserviceName,
            ],
            [
                'display_name' => \ucfirst(\str_replace(['_', '-'], ' ', $microserviceName)),
                'destination_key' => $destinationKey,
            ]
        );

        self::cache(true);
        return $microService;
    }

    protected function headers(string $method = '', array $additionalHeaders = [])
    {
        $headers = [
            'origin' => self::origin(),
            'Accept' => 'application/json',
            'Authorization' => 'application/json',
        ];
        if ($method == 'put') $headers['_method'] = 'put';
        return \array_merge($headers, $additionalHeaders);
    }

    protected static function origin(bool $inbound = false, int|null $localPort = null)
    {
        $origin = $inbound ? (string) request()->header('origin') : request()->getSchemeAndHttpHost();

        $origin = \str_replace(['http://', 'https://'], '', $origin);

        if ($localPort && \in_array($origin, ['localhost', '127.0.0.1'])) {
            $origin = "{$origin}:{$localPort}";
        }

        return $origin;
    }

    protected static function cache(bool $force = false)
    {
        try {
            if ($force) self::forgetCache();

            return Cache::rememberForever('micro-services', fn () => self::query()->get());
        } catch (Exception $exception) {
            abort(503, 'micro-service package is not installed yet or your configuration file is not configured yet. please run "php artisan vendor:publish" and "php artisan ms:install" commands and make sure that you set config file required values. Exception:' . $exception->getMessage());
        }
    }

    protected static function forgetCache() : void
    {
        Cache::forget('micro-services');
    }

    public static function refreshCache() : void
    {
        self::cache(true);
    }

    public static function announceSecretChange(string $secret): void
    {
        self::cache()->each(function ($microservice) use ($secret) {
            self::post(
                microserviceName: $microservice->name,
                uri: 'micro-services',
                data: [
                    'name' => config('microservice.micro_service_name'),
                    'origin' => self::origin(),
                    'secret' => $secret,
                ],
            );
        });
        
        $envFile = App::environmentFilePath();
        $envContent = \file_get_contents($envFile);

        $envContent = change_env_key_value(envContent: $envContent, envKey: 'MS_LOCAL_SECRET', envKeyValue: $secret);

        \file_put_contents($envFile, $envContent);

        Artisan::call('config:cache');
    }

    protected function encodeRequestBody(array $data, bool $establish = false)
    {
        return RequestEncryption::encrypt(
            data: $data,
            key: $establish ? config('microservice.project_secret') : $this->microservice->destination_key,
        );
    }

    public static function decodeRequest(bool $establish = false)
    {
        try {
            request_passed_ssl_configuration();

            $originMs = self::firstMsBy('origin', self::origin(true));

            if ((! $establish) && (! $originMs)) return false;

            $isDecrypted = RequestEncryption::decrypt(
                request: request(),
                key: (string) ($establish ? config('microservice.project_secret') : config('microservice.local_secret')),
            );

            if (! $isDecrypted) return false;

            if (! $establish) self::setCurrentRequestOriginMs($originMs);

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    protected static function setCurrentRequestOriginMs(MicroServiceMap $originMs)
    {
        request()->currentRequestMs = $originMs;
    }

    public static function current()
    {
        return request()->currentRequestMs ?? self::testingMicroservice();
    }

    public static function testingMicroservice()
    {
        if (! App::environment(['local', 'testing'])) return null;

        request()->currentRequestMs = self::query()->first() ?? MicroServiceMap::factory()->create();

        return request()->currentRequestMs;
    }
}
