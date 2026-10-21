<?php

namespace Otas\MicroService\Actions;

use Otas\MicroService\Helpers\Origin;
use Otas\MicroService\Helpers\RequestEncryption;
use Otas\MicroService\Services\MicroServiceMapService;

class EstablishConnectionAction
{
    public static function handle(string $microserviceName, string $origin, int|null $localPort = null)
    {
        if (config('microservice.micro_service_name') == '') abort(503, 'current service name is not set yet in "microservice" config file');

        if (config('microservice.project_secret') == '') abort(503, 'project secret is not set yet in "microservice" config file');

        $receiveKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        $response = SendRequestAction::handle(
            method: 'post',
            origin: Origin::clean($origin),
            uri: 'micro-services',
            data: [
                'name' => config('microservice.micro_service_name'),
                'origin' => Origin::current(localPort: $localPort),
                ...$receiveKeys,
            ],
            secretKey: config('microservice.project_secret'),
        );

        if (! $response->ok()) return $response;

        $sendKeys = RequestEncryption::decryptData(encryptedFields: (array) $response->json(), key: config('microservice.project_secret'));

        if (! $sendKeys) abort(503, 'the other service response could not be decrypted');

        MicroServiceMapService::save(
            name: $microserviceName,
            origin: $origin,
            receiveKeys: $receiveKeys,
            sendKeys: $sendKeys,
        );

        return $response;
    }
}
