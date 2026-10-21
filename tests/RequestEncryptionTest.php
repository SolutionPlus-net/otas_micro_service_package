<?php

namespace Otas\MicroService\Tests;

use Illuminate\Http\Request;
use Otas\MicroService\Helpers\RequestEncryption;

class RequestEncryptionTest extends TestCase
{
    const KEY = 'test-secret-key';

    public function test_post_request_is_decrypted_with_the_same_key(): void
    {
        $request = $this->encryptedRequest(method: 'POST', data: ['name' => 'tenant', 'is_active' => true]);

        $this->assertTrue(RequestEncryption::decrypt(request: $request, key: self::KEY));

        $this->assertSame(['name' => 'tenant', 'is_active' => true], $request->all());
    }

    public function test_get_request_is_decrypted_with_the_same_key(): void
    {
        $request = $this->encryptedRequest(method: 'GET', data: ['page' => 2]);

        $this->assertTrue(RequestEncryption::decrypt(request: $request, key: self::KEY));

        $this->assertSame(['page' => 2], $request->query->all());
    }

    public function test_request_is_rejected_with_a_wrong_key(): void
    {
        $request = $this->encryptedRequest(method: 'POST', data: ['name' => 'tenant']);

        $this->assertFalse(RequestEncryption::decrypt(request: $request, key: 'wrong-key'));
    }

    public function test_request_is_rejected_when_any_encrypted_field_is_changed(): void
    {
        foreach (RequestEncryption::ENCRYPTED_FIELDS as $field) {
            $fields = RequestEncryption::encrypt(data: ['name' => 'tenant'], key: self::KEY);

            $decodedValue = \base64_decode($fields[$field]);
            $decodedValue[0] = $decodedValue[0] === 'a' ? 'b' : 'a';
            $fields[$field] = \base64_encode($decodedValue);

            $request = Request::create(uri: '/api/test', method: 'POST', parameters: $fields);

            $this->assertFalse(RequestEncryption::decrypt(request: $request, key: self::KEY), "changed {$field} was accepted");
        }
    }

    public function test_request_is_rejected_when_fields_are_missing(): void
    {
        $request = Request::create(uri: '/api/test', method: 'POST', parameters: ['name' => 'tenant']);

        $this->assertFalse(RequestEncryption::decrypt(request: $request, key: self::KEY));
    }

    public function test_request_is_rejected_after_it_expires(): void
    {
        $request = $this->encryptedRequest(method: 'POST', data: ['name' => 'tenant']);

        $this->travel(121)->seconds();

        $this->assertFalse(RequestEncryption::decrypt(request: $request, key: self::KEY));
    }

    public function test_same_request_is_rejected_when_sent_again(): void
    {
        $fields = RequestEncryption::encrypt(data: ['name' => 'tenant'], key: self::KEY);

        $firstRequest = Request::create(uri: '/api/test', method: 'POST', parameters: $fields);
        $repeatedRequest = Request::create(uri: '/api/test', method: 'POST', parameters: $fields);

        $this->assertTrue(RequestEncryption::decrypt(request: $firstRequest, key: self::KEY));

        $this->assertFalse(RequestEncryption::decrypt(request: $repeatedRequest, key: self::KEY));
    }

    private function encryptedRequest(string $method, array $data): Request
    {
        return Request::create(
            uri: '/api/test',
            method: $method,
            parameters: RequestEncryption::encrypt(data: $data, key: self::KEY),
        );
    }
}
