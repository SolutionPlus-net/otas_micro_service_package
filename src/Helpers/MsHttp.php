<?php

namespace Otas\MicroService\Helpers;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Otas\MicroService\Models\MicroServiceMap;

class MsHttp
{
    const ACCESS_KEY_HEADER = 'X-MS-Access-Key';

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

        $microService->load('token');

        if (! $microService->token) abort(503, 'can\'t find the selected service keys. please run "php artisan ms:establish" again');

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
        if (config('microservice.micro_service_name') == '') abort(503, 'current service name is not set yet in "microservice" config file');

        if (config('microservice.project_secret') == '') abort(503, 'project secret is not set yet in "microservice" config file');

        $receiveKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        $response = self::send(
            method: 'post',
            microserviceName: $microserviceName,
            uri: 'micro-services',
            data: [
                'name' => config('microservice.micro_service_name'),
                'origin' => self::origin(localPort: $localPort),
                ...$receiveKeys,
            ],
            origin: $origin
        );

        if (! $response->ok()) return $response;

        $sendKeys = RequestEncryption::decryptData(encryptedFields: (array) $response->json(), key: config('microservice.project_secret'));

        if (! $sendKeys) abort(503, 'the other service response could not be decrypted');

        self::saveMicroservice(
            microserviceName: $microserviceName,
            origin: $origin,
            receiveKeys: $receiveKeys,
            sendKeys: $sendKeys,
        );

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
        return Http::withHeaders($http->headers($method, $additionalHeaders, $establish))
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
        return MicroServiceMap::on(self::connectionName());
    }

    protected static function connectionName()
    {
        return config('microservice.db_connection_name') ?: null;
    }

    public static function saveMicroservice(string $microserviceName, string $origin, array $receiveKeys, array $sendKeys)
    {
        $microService = DB::connection(self::connectionName())->transaction(function () use ($microserviceName, $origin, $receiveKeys, $sendKeys) {
            $microService = self::query()->updateOrCreate(
                [
                    'name' => $microserviceName,
                ],
                [
                    'display_name' => \ucfirst(\str_replace(['_', '-'], ' ', $microserviceName)),
                    'origin' => $origin,
                ]
            );

            $microService->token()->updateOrCreate([], [
                'receive_secret_old_key' => $receiveKeys['secret_key'],
                'receive_secret_current_key' => $receiveKeys['secret_key'],
                'send_secret_key' => $sendKeys['secret_key'],

                'receive_access_old_key' => $receiveKeys['access_key'],
                'receive_access_current_key' => $receiveKeys['access_key'],
                'send_access_key' => $sendKeys['access_key'],
            ]);

            return $microService;
        });

        self::cache(true);

        return $microService;
    }

    protected function headers(string $method = '', array $additionalHeaders = [], bool $establish = false)
    {
        $headers = [
            'origin' => self::origin(),
            'Accept' => 'application/json',
            'Authorization' => 'application/json',
        ];
        if ($method == 'put') $headers['_method'] = 'put';

        if (! $establish) {
            $headers[self::ACCESS_KEY_HEADER] = $this->microservice->token->send_access_key;
        }

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

    public static function updateOrigin(string $microserviceName, string $origin): bool
    {
        $updatedRowsCount = self::query()
            ->where('name', $microserviceName)
            ->update(['origin' => \str_replace(['http://', 'https://'], '', $origin)]);

        self::cache(true);

        return $updatedRowsCount > 0;
    }

    protected function encodeRequestBody(array $data, bool $establish = false)
    {
        return RequestEncryption::encrypt(
            data: $data,
            key: $establish ? config('microservice.project_secret') : $this->microservice->token->send_secret_key,
        );
    }

    public static function decodeRequest(bool $establish = false)
    {
        try {
            request_passed_ssl_configuration();

            $originMs = self::firstMsBy('origin', self::origin(true));

            if ((! $establish) && (! $originMs)) return false;

            $isDecrypted = $establish
                ? RequestEncryption::decrypt(request: request(), key: (string) config('microservice.project_secret'))
                : self::decryptWithReceiveKeys($originMs);

            if (! $isDecrypted) return false;

            if (! $establish) self::setCurrentRequestOriginMs($originMs);

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    protected static function decryptWithReceiveKeys(MicroServiceMap $originMs): bool
    {
        $token = $originMs->token()->first();

        if (! $token) {
            return false;
        }

        // Checked before decrypting, so a request without a valid access key never costs any decryption.
        $secretKey = $token->receiveSecretKeyFor(accessKey: (string) request()->header(self::ACCESS_KEY_HEADER));

        if (! $secretKey) {
            return false;
        }

        return RequestEncryption::decrypt(request: request(), key: $secretKey);
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
