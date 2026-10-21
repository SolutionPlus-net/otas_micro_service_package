<?php

namespace Otas\MicroService\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RequestEncryption
{
    const CIPHER = 'aes-256-gcm';

    const ACCESS_KEY_HEADER = 'X-MS-Access-Key';

    const ENCRYPTED_FIELDS = [
        'ms_iv',
        'ms_tag',
        'ms_data',
    ];

    public static function encrypt(array $data, string $key): array
    {
        $iv = \random_bytes(12);

        $payload = [
            'data' => $data,
            'sent_at' => now()->timestamp,
            'nonce' => \bin2hex(\random_bytes(16)),
        ];

        $encryptedData = \openssl_encrypt(
            data: \json_encode($payload),
            cipher_algo: self::CIPHER,
            passphrase: self::hashKey($key),
            options: OPENSSL_RAW_DATA,
            iv: $iv,
            tag: $tag,
        );

        return [
            'ms_iv' => \base64_encode($iv),
            'ms_tag' => \base64_encode($tag),
            'ms_data' => \base64_encode($encryptedData),
        ];
    }

    public static function decrypt(Request $request, string $key): bool
    {
        $data = self::decryptData(encryptedFields: $request->only(self::ENCRYPTED_FIELDS), key: $key);

        if ($data === null) return false;

        self::replaceRequestData($request, $data);

        return true;
    }

    public static function decryptData(array $encryptedFields, string $key): array|null
    {
        $payload = self::decryptPayload($encryptedFields, $key);

        if (! $payload) return null;

        if (self::isExpired($payload['sent_at'])) return null;

        if (self::isReplayed($payload['nonce'])) return null;

        return $payload['data'];
    }

    private static function decryptPayload(array $encryptedFields, string $key): array|null
    {
        $iv = self::decodedField($encryptedFields, 'ms_iv');
        $tag = self::decodedField($encryptedFields, 'ms_tag');

        if (\strlen($iv) !== 12 || \strlen($tag) !== 16) return null;

        $json = \openssl_decrypt(
            data: self::decodedField($encryptedFields, 'ms_data'),
            cipher_algo: self::CIPHER,
            passphrase: self::hashKey($key),
            options: OPENSSL_RAW_DATA,
            iv: $iv,
            tag: $tag,
        );

        $payload = $json === false ? null : \json_decode($json, true);

        return \is_array($payload) ? $payload : null;
    }

    private static function isExpired(int $sentAt): bool
    {
        return \abs(now()->timestamp - $sentAt) > self::requestLifetimeInSeconds();
    }

    private static function isReplayed(string $nonce): bool
    {
        // A request is accepted from (sent_at - lifetime) to (sent_at + lifetime), so its nonce is kept for both.
        return ! Cache::add("ms-nonce:{$nonce}", true, 2 * self::requestLifetimeInSeconds());
    }

    private static function replaceRequestData(Request $request, array $data): void
    {
        foreach (self::ENCRYPTED_FIELDS as $field) {
            $request->query->remove($field);
            $request->request->remove($field);
        }

        $request->merge($data);
    }

    private static function decodedField(array $encryptedFields, string $field): string
    {
        $value = $encryptedFields[$field] ?? null;

        return \is_string($value) ? (string) \base64_decode($value, true) : '';
    }

    private static function hashKey(string $key): string
    {
        return \hash('sha256', $key, true);
    }

    private static function requestLifetimeInSeconds(): int
    {
        return config('microservice.request_expires_after_seconds', 120);
    }
}
