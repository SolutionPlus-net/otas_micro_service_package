<?php

namespace Otas\MicroService\Actions;

use Exception;
use Otas\MicroService\Helpers\Origin;
use Otas\MicroService\Helpers\RequestEncryption;
use Otas\MicroService\Services\MicroServiceMapService;

class DecodeRequestAction
{
    public static function handle(bool $establish = false): bool
    {
        try {
            request_passed_ssl_configuration();

            return $establish ? self::decryptWithProjectSecret() : self::decryptWithReceiveKeys();
        } catch (Exception $exception) {
            return false;
        }
    }

    private static function decryptWithProjectSecret(): bool
    {
        return RequestEncryption::decrypt(request: request(), key: (string) config('microservice.project_secret'));
    }

    private static function decryptWithReceiveKeys(): bool
    {
        $originMs = MicroServiceMapService::findByOrigin(Origin::incoming());

        if (! $originMs) {
            return false;
        }

        // Checked before decrypting, so a request without a valid access key never costs any decryption.
        $secretKey = $originMs->token()->first()?->receiveSecretKeyFor(
            accessKey: (string) request()->header(RequestEncryption::ACCESS_KEY_HEADER),
        );

        if (! $secretKey) {
            return false;
        }

        if (! RequestEncryption::decrypt(request: request(), key: $secretKey)) {
            return false;
        }

        request()->currentRequestMs = $originMs;

        return true;
    }
}
