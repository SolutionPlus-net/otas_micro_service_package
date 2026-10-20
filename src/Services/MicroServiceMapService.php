<?php

namespace Otas\MicroService\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Otas\MicroService\Helpers\Origin;
use Otas\MicroService\Models\MicroServiceMap;

class MicroServiceMapService
{
    const CACHE_KEY = 'micro-services';

    public static function findByName(string $name): MicroServiceMap|null
    {
        return self::firstBy(column: 'name', value: $name);
    }

    public static function findByOrigin(string $origin): MicroServiceMap|null
    {
        return self::firstBy(column: 'origin', value: $origin);
    }

    public static function findConnectedByName(string $name): MicroServiceMap
    {
        $microService = self::findByName($name);

        if (! $microService) abort(503, 'the service you try to communicate with is not exist yet in linked services list');

        if (! $microService->origin) abort(503, 'can\'t find the selected service origin');

        $microService->load('token');

        if (! $microService->token) abort(503, 'can\'t find the selected service keys. please run "php artisan ms:establish" again');

        return $microService;
    }

    public static function connectedServices()
    {
        return self::query()->has('token')->get();
    }

    public static function save(string $name, string $origin, array $receiveKeys, array $sendKeys): MicroServiceMap
    {
        $microService = DB::connection(self::connectionName())->transaction(function () use ($name, $origin, $receiveKeys, $sendKeys) {
            $microService = self::query()->updateOrCreate(
                [
                    'name' => $name,
                ],
                [
                    'display_name' => \ucfirst(\str_replace(['_', '-'], ' ', $name)),
                    'origin' => Origin::clean($origin),
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

        self::refreshCache();

        return $microService;
    }

    public static function updateOrigin(string $name, string $origin): bool
    {
        $updatedRowsCount = self::query()
            ->where('name', $name)
            ->update(['origin' => Origin::clean($origin)]);

        self::refreshCache();

        return $updatedRowsCount > 0;
    }

    public static function refreshCache()
    {
        Cache::forget(self::CACHE_KEY);

        return self::cached();
    }

    // Runs on the package connection without changing the app's default connection (e.g. a tenant database).
    public static function query()
    {
        return MicroServiceMap::on(self::connectionName());
    }

    private static function firstBy(string $column, string $value): MicroServiceMap|null
    {
        return self::cached()->firstWhere($column, $value) ?? self::refreshCache()->firstWhere($column, $value);
    }

    private static function cached()
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->get());
        } catch (Exception $exception) {
            abort(503, 'micro-service package is not installed yet or your configuration file is not configured yet. please run "php artisan vendor:publish" and "php artisan ms:install" commands and make sure that you set config file required values. Exception:' . $exception->getMessage());
        }
    }

    private static function connectionName(): string|null
    {
        return config('microservice.db_connection_name') ?: null;
    }
}
