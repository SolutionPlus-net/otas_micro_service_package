<?php

namespace Otas\MicroService\Console\Commands;

use Illuminate\Console\Command;
use Otas\MicroService\Services\MicroServiceMapService;
use Otas\MicroService\Jobs\RenewKeysJob;

class MicroServiceRenewKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ms:renew-keys';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renew the receive keys of every connected microservice';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $connectedServices = MicroServiceMapService::connectedServices();

        foreach ($connectedServices as $connectedService) {
            RenewKeysJob::dispatch($connectedService);
        }

        $this->info("Keys renewal is queued for {$connectedServices->count()} services");

        return Command::SUCCESS;
    }
}
