# Otas/MicroService

otas/microservice is a Laravel package for dealing with specific case of micro-services connection so, it doesn't suit all needs or handle all connection cases.

## Table of Content
[Installation](#installation)

[Connecting two services](#connecting-two-services)

[Usage](#usage)

[Keys renewal](#keys-renewal)

[When a service origin changes](#when-a-service-origin-changes)

[How a request is protected](#how-a-request-is-protected)

[Upgrading from 1.x](#upgrading-from-1x)

[License](#license)

## Installation

You can install the package using composer

```bash
# cli commands

composer require otas/microservice

php artisan ms:install
```

#### Important:
> Don't forget to modify the added env key `MS_GLOBAL_PROJECT_SECRET` to exactly match all other microservices projects `MS_GLOBAL_PROJECT_SECRET` then run:

```bash
php artisan config:cache
```

* Register both package middlewares in your app:

```php
# Laravel 11+ structure: bootstrap/app.php

->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'micro-service' => \Otas\MicroService\Http\Middleware\MicroServiceMiddleware::class,
        'micro-service-establish-connection' => \Otas\MicroService\Http\Middleware\MicroServiceEstablishConnectionMiddleware::class,
    ]);
})
```

```php
# Kernel structure: app/Http/Kernel.php

protected $middlewareAliases = [
    //
    'micro-service' => \Otas\MicroService\Http\Middleware\MicroServiceMiddleware::class,
    'micro-service-establish-connection' => \Otas\MicroService\Http\Middleware\MicroServiceEstablishConnectionMiddleware::class,
];
```

* Then apply `micro-service` on the routes group you will create to communicate with other microservices

```php
Route::group([
    'middleware' => [
        'micro-service',
    ]
], function () {
    // routes communicates with other microservices goes here
});
```

Using `micro-service` middleware on micro-services routes group is a must as it's responsible about decoding requests in order to make you deal with it as you usually do. Otherwise it will lead to unexpected results.

#### Note:
> Don't use `micro-service-establish-connection` on any request yourself. It's already running on the predefined `micro-services` `store` route.

## Connecting two services

Run the next command on **one** of the two services only. It asks for the other service name and origin, then both services save the keys of each other.

```bash
php artisan ms:establish
```

* The origin is the host only, without `http://` or `https://` (EX: `payment.com` or `localhost:8001`).
* Running it again from the same service replaces all the keys. Use it if the keys of two services ever stop matching.

## Usage

There is some predefined methods that will help you communicate with other services shown below:

```php
// EX: $microserviceName = 'payment';
// EX: $uri = 'license-payments' || 'orders' ...etc;

// `$params` and `$data` should be ['key' => 'value'] pair array
// which represent query string in get requests or inputs in post requests

$response = MsHttp::get($microserviceName, $uri, $params = []);

$response = MsHttp::post($microserviceName, $uri, $data = []);

$response = MsHttp::put($microserviceName, $uri, $data = []);
```

The above methods are must to be used if you need to encrypt requests between micro-services.

Inside a request that passed the `micro-service` middleware, `MsHttp::current()` returns the service that sent it.

## Keys renewal

Every two connected services have their own keys, saved encrypted in the `microservice_tokens` table. Each service renews the keys it receives with by itself.

Add the renewal command to your app scheduler. It should run every 30 minutes or less:

```php
$schedule->command('ms:renew-keys')->everyThirtyMinutes()->onOneServer();
```

* The command queues one job for each connected service, so a queue worker must be running.
* `onOneServer()` needs a shared cache driver (redis, database ...etc) if the app runs on more than one server.
* The old keys keep working until the next renewal, so requests that are already on their way don't fail.
* If sending the new keys fails, the job sends the **same** keys again after 1, 2, 5, 10 and 15 minutes. Every failed attempt shows the reason in `failed_jobs`.
* While those retries are running, `ms:renew-keys` doesn't renew that service, because a new renewal would drop the old keys the other service still uses.
* If every retry fails, the next scheduled run renews as usual, and the other service can't reach this one until a renewal reaches it. If both services kept failing to reach each other, run `ms:establish` once both are back.

## When a service origin changes

If a connected service moves to a new origin, run the next command on every service that talks to it. The keys stay the same.

```bash
php artisan ms:update-origin landlord new-landlord.com
```

On the service that moved, update `APP_URL` then run `php artisan config:cache`, as queued jobs send their origin from it.

## How a request is protected

1. `X-MS-Access-Key` header: checked first. A request without a valid access key is rejected before any decryption.
2. The data is encrypted with `aes-256-gcm`. The request body only has these fields:
   * `ms_data`: the encrypted data.
   * `ms_iv`: a random value that makes every encryption different.
   * `ms_tag`: proves that nothing in the request was changed.
3. The encrypted data also carries the time it was sent and a random one time value (nonce). A request older than `request_expires_after_seconds` (default 120) or sent twice is rejected.

#### Note:
> The servers clocks must be in sync (EX: NTP) within `request_expires_after_seconds`, otherwise all requests are rejected.

## Upgrading from 1.x

This version uses a new request format, so a service on 1.x can't talk to a service on this version. Upgrade all connected services together.

1. Update the package to the new major version on every service.
    * A service that still uses `solutionplus/microservice` must move to `otas/microservice` and replace the `Solutionplus\MicroService` namespace with `Otas\MicroService`.
    * Laravel 11 or newer is required.
2. Publish and run the new migrations:

    ```bash
    php artisan vendor:publish --tag=micro_service_migrations
    php artisan migrate
    ```

    They create the `microservice_tokens` table and drop the unused `destination_key` column.
3. Update `config/microservice.php` by hand (the package doesn't merge its config):
    * Remove `local_secret`, and remove `MS_LOCAL_SECRET` from `.env`.
    * Optionally add `'request_expires_after_seconds' => 120,`.
4. Schedule `ms:renew-keys` as shown in [Keys renewal](#keys-renewal).
5. Run `php artisan ms:establish` once for every two connected services, from one side only.

## License

Otas/MicroService package is limited and proprietary software belongs to Otas.net company.
