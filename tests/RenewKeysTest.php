<?php

namespace Otas\MicroService\Tests;

use Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Otas\MicroService\Helpers\RequestEncryption;
use Otas\MicroService\Jobs\RenewKeysJob;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Models\MicroServiceToken;

class RenewKeysTest extends TestCase
{
    private MicroServiceMap $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migratePackageTables();

        $this->tenant = MicroServiceMap::factory()
            ->has(MicroServiceToken::factory(), 'token')
            ->create(['name' => 'tenant', 'origin' => 'tenant.test']);
    }

    public function test_command_queues_one_job_for_every_connected_service(): void
    {
        Queue::fake();

        MicroServiceMap::factory()->has(MicroServiceToken::factory(), 'token')->create(['name' => 'payment', 'origin' => 'payment.test']);
        MicroServiceMap::factory()->create(['name' => 'not_connected', 'origin' => 'not-connected.test']);

        $this->artisan('ms:renew-keys')->assertSuccessful();

        Queue::assertPushed(RenewKeysJob::class, 2);
    }

    public function test_job_moves_the_current_keys_to_old_and_sends_the_new_keys(): void
    {
        $tokenBefore = $this->tenant->token;

        Http::fake([
            'tenant.test/api/micro-services/renew-keys' => function (Request $request) use (&$sentKeys, $tokenBefore) {
                $sentKeys = RequestEncryption::decryptData(encryptedFields: $request->data(), key: $tokenBefore->send_secret_key);

                return Http::response(['message' => 'renewed']);
            },
        ]);

        (new RenewKeysJob($this->tenant))->handle();

        $tokenAfter = $this->tenant->token()->first();

        $this->assertSame($tokenBefore->receive_secret_current_key, $tokenAfter->receive_secret_old_key);
        $this->assertSame($tokenBefore->receive_access_current_key, $tokenAfter->receive_access_old_key);

        $this->assertSame($sentKeys['secret_key'], $tokenAfter->receive_secret_current_key);
        $this->assertSame($sentKeys['access_key'], $tokenAfter->receive_access_current_key);
    }

    public function test_keys_the_other_service_still_uses_keep_working_when_sending_fails(): void
    {
        Queue::fake();

        $tokenBefore = $this->tenant->token;

        Http::fake(['tenant.test/*' => Http::response(status: 500)]);

        try {
            (new RenewKeysJob($this->tenant))->handle();
            $this->fail('the job should fail when the other service does not save the new keys');
        } catch (Exception $exception) {
            $this->assertStringContainsString('renewing keys with "tenant" failed on attempt 1', $exception->getMessage());
        }

        $tokenAfter = $this->tenant->token()->first();

        $this->assertSame(
            $tokenBefore->receive_secret_current_key,
            $tokenAfter->receiveSecretKeyFor(accessKey: $tokenBefore->receive_access_current_key),
        );
    }

    public function test_other_service_new_keys_are_saved_as_send_keys(): void
    {
        $token = $this->tenant->token;

        $newKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => $token->receive_access_current_key])
            ->postJson('/api/micro-services/renew-keys', RequestEncryption::encrypt(data: $newKeys, key: $token->receive_secret_current_key))
            ->assertOk();

        $tokenAfter = $token->fresh();

        $this->assertSame($newKeys['secret_key'], $tokenAfter->send_secret_key);
        $this->assertSame($newKeys['access_key'], $tokenAfter->send_access_key);

        $this->assertSame($token->receive_secret_current_key, $tokenAfter->receive_secret_current_key);
    }

    public function test_saving_the_same_send_keys_again_is_harmless(): void
    {
        $token = $this->tenant->token;

        $newKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        foreach ([1, 2] as $attempt) {
            $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => $token->receive_access_current_key])
                ->postJson('/api/micro-services/renew-keys', RequestEncryption::encrypt(data: $newKeys, key: $token->receive_secret_current_key))
                ->assertOk();
        }

        $tokenAfter = $token->fresh();

        $this->assertSame($newKeys['secret_key'], $tokenAfter->send_secret_key);
        $this->assertSame($newKeys['access_key'], $tokenAfter->send_access_key);
    }

    public function test_renew_endpoint_rejects_a_request_without_valid_keys(): void
    {
        $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => generate_key()])
            ->postJson('/api/micro-services/renew-keys', RequestEncryption::encrypt(data: [], key: generate_key()))
            ->assertStatus(405);
    }

    public function test_failed_send_is_retried_later_with_the_same_keys(): void
    {
        Queue::fake();
        $this->freezeTime();

        $tokenBefore = $this->tenant->token;
        $sentKeys = [];

        Http::fake([
            'tenant.test/*' => function (Request $request) use (&$sentKeys, $tokenBefore) {
                $sentKeys[] = RequestEncryption::decryptData(encryptedFields: $request->data(), key: $tokenBefore->send_secret_key);

                return Http::response(status: 500);
            },
        ]);

        $this->runFailingJob(new RenewKeysJob($this->tenant));

        $tokenAfterFirstAttempt = $this->tenant->token()->first();
        $retry = $this->lastQueuedJob();

        $this->assertSame($sentKeys[0], $retry->newReceiveKeys);
        $this->assertSame(2, $retry->attempt);
        $this->assertEquals(now()->addMinute(), $retry->delay);

        $this->runFailingJob($retry);

        $tokenAfterRetry = $this->tenant->token()->first();

        $this->assertSame($sentKeys[0], $sentKeys[1]);

        $this->assertSame($tokenBefore->receive_secret_current_key, $tokenAfterRetry->receive_secret_old_key);
        $this->assertSame($tokenAfterFirstAttempt->receive_secret_old_key, $tokenAfterRetry->receive_secret_old_key);
        $this->assertSame($tokenAfterFirstAttempt->receive_secret_current_key, $tokenAfterRetry->receive_secret_current_key);
    }

    public function test_it_gives_up_after_the_last_retry_and_the_old_key_still_works(): void
    {
        Queue::fake();
        Http::fake(['tenant.test/*' => Http::response(status: 500)]);

        $tokenBefore = $this->tenant->token;

        $failureMessages = [$this->runFailingJob(new RenewKeysJob($this->tenant))];

        foreach (RenewKeysJob::RETRY_AFTER_MINUTES as $retry) {
            $failureMessages[] = $this->runFailingJob($this->lastQueuedJob());
        }

        foreach ($failureMessages as $index => $failureMessage) {
            $this->assertStringContainsString('failed on attempt ' . ($index + 1), $failureMessage);
        }

        Http::assertSentCount(6);
        Queue::assertPushed(RenewKeysJob::class, 5);

        $this->assertFalse(RenewKeysJob::isRetrying($this->tenant));

        $this->assertSame(
            $tokenBefore->receive_secret_current_key,
            $this->tenant->token()->first()->receiveSecretKeyFor(accessKey: $tokenBefore->receive_access_current_key),
        );
    }

    public function test_success_stops_retrying_and_the_next_renewal_renews_again(): void
    {
        Queue::fake();
        Http::fake(['tenant.test/*' => Http::sequence()->push(status: 500)->push(['message' => 'renewed'])->push(['message' => 'renewed'])]);

        $this->runFailingJob(new RenewKeysJob($this->tenant));

        $this->assertTrue(RenewKeysJob::isRetrying($this->tenant));

        $this->lastQueuedJob()->handle();

        $this->assertFalse(RenewKeysJob::isRetrying($this->tenant));

        $tokenAfterRetry = $this->tenant->token()->first();

        (new RenewKeysJob($this->tenant))->handle();

        $this->assertSame($tokenAfterRetry->receive_secret_current_key, $this->tenant->token()->first()->receive_secret_old_key);
    }

    public function test_command_skips_a_service_that_is_retrying(): void
    {
        Queue::fake();
        Http::fake(['tenant.test/*' => Http::response(status: 500)]);

        $payment = MicroServiceMap::factory()->has(MicroServiceToken::factory(), 'token')->create(['name' => 'payment', 'origin' => 'payment.test']);

        $this->runFailingJob(new RenewKeysJob($this->tenant));

        $this->artisan('ms:renew-keys')
            ->expectsOutput('Keys renewal is queued for 1 services')
            ->assertSuccessful();

        Queue::assertPushed(RenewKeysJob::class, fn (RenewKeysJob $job) => $job->microService->is($payment));
    }

    public function test_a_renewal_queued_earlier_does_not_renew_while_retrying(): void
    {
        Queue::fake();
        Http::fake(['tenant.test/*' => Http::response(status: 500)]);

        $queuedEarlier = new RenewKeysJob($this->tenant);

        $this->runFailingJob(new RenewKeysJob($this->tenant));

        $tokenBefore = $this->tenant->token()->first();

        $queuedEarlier->handle();

        Http::assertSentCount(1);

        $this->assertSame($tokenBefore->receive_secret_current_key, $this->tenant->token()->first()->receive_secret_current_key);
    }

    public function test_retry_stops_when_the_connection_was_established_again(): void
    {
        Queue::fake();
        Http::fake(['tenant.test/*' => Http::response(status: 500)]);

        $this->runFailingJob(new RenewKeysJob($this->tenant));

        $this->tenant->token()->first()->update([
            'receive_secret_current_key' => generate_key(),
            'receive_access_current_key' => generate_key(),
        ]);

        $this->lastQueuedJob()->handle();

        Http::assertSentCount(1);

        $this->assertFalse(RenewKeysJob::isRetrying($this->tenant));
    }

    public function test_renewal_works_as_before_when_the_cache_is_cleared(): void
    {
        Queue::fake();
        Http::fake(['tenant.test/*' => Http::sequence()->push(status: 500)->push(['message' => 'renewed'])]);

        $this->runFailingJob(new RenewKeysJob($this->tenant));

        $tokenBefore = $this->tenant->token()->first();

        Cache::flush();

        (new RenewKeysJob($this->tenant))->handle();

        $this->assertSame($tokenBefore->receive_secret_current_key, $this->tenant->token()->first()->receive_secret_old_key);
    }

    private function runFailingJob(RenewKeysJob $job): string
    {
        try {
            $job->handle();
        } catch (Exception $exception) {
            return $exception->getMessage();
        }

        $this->fail('the job should fail when the other service does not save the new keys');
    }

    private function lastQueuedJob(): RenewKeysJob
    {
        return Queue::pushed(RenewKeysJob::class)->last();
    }
}
