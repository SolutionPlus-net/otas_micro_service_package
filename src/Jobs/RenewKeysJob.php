<?php

namespace Otas\MicroService\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Models\MicroServiceMap;

// Encrypted because a retry carries the new receive keys in the queue payload.
class RenewKeysJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const RETRY_AFTER_MINUTES = [1, 2, 5, 10, 15];

    public function __construct(
        public MicroServiceMap $microService,
        public array|null $newReceiveKeys = null,
        public int $attempt = 1,
    ) {
    }

    public static function isRetrying(MicroServiceMap $microService): bool
    {
        return Cache::has(self::retryingCacheKey($microService));
    }

    // Laravel would retry by running the job from the start, which renews the keys again. retryLater() queues the retries instead.
    public function tries(): int
    {
        return 1;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("ms-renew-keys:{$this->microService->id}"))->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $token = $this->microService->token()->first();

        if ($this->newReceiveKeys === null) {
            // A renewal queued before the retries started must not renew again: that would drop the old key the other service still sends with.
            if (self::isRetrying($this->microService)) return;

            // Saved before sending: if sending fails, the other service still sends with what is now our old key.
            $this->newReceiveKeys = $token->renewReceiveKeys();
        }

        // ms:establish replaced the keys while this retry waited, so these keys are no longer ours.
        if ($this->newReceiveKeys['access_key'] !== $token->receive_access_current_key) {
            $this->stopRetrying();

            return;
        }

        $response = MsHttp::post(
            microserviceName: $this->microService->name,
            uri: 'micro-services/renew-keys',
            data: $this->newReceiveKeys,
        );

        if ($response->ok()) {
            $this->stopRetrying();

            return;
        }

        $this->retryLater();

        throw new Exception("renewing keys with \"{$this->microService->name}\" failed on attempt {$this->attempt}. response_status_code: {$response->status()}, response_body: {$response->body()}");
    }

    private function retryLater(): void
    {
        $retryAfterMinutes = self::RETRY_AFTER_MINUTES[$this->attempt - 1] ?? null;

        if ($retryAfterMinutes === null) {
            $this->stopRetrying();

            return;
        }

        // Expires on its own, so a lost retry can't stop the renewals forever.
        Cache::put(self::retryingCacheKey($this->microService), true, now()->addHour());

        self::dispatch(
            microService: $this->microService,
            newReceiveKeys: $this->newReceiveKeys,
            attempt: $this->attempt + 1,
        )->delay(now()->addMinutes($retryAfterMinutes));
    }

    private function stopRetrying(): void
    {
        Cache::forget(self::retryingCacheKey($this->microService));
    }

    private static function retryingCacheKey(MicroServiceMap $microService): string
    {
        return "ms-renew-keys-retrying:{$microService->id}";
    }
}
