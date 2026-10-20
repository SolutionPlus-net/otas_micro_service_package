<?php

namespace Otas\MicroService\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Models\MicroServiceMap;

class RenewKeysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public MicroServiceMap $microService)
    {
    }

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
        // Saved before sending: if sending fails, the other service still sends with what is now our old key.
        $newReceiveKeys = $this->microService->token()->first()->renewReceiveKeys();

        $response = MsHttp::post(
            microserviceName: $this->microService->name,
            uri: 'micro-services/renew-keys',
            data: $newReceiveKeys,
        );

        if (! $response->ok()) {
            throw new Exception("renewing keys with \"{$this->microService->name}\" failed. response_status_code: {$response->status()}, response_body: {$response->body()}");
        }
    }
}
