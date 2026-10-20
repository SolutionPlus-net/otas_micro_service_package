<?php

namespace Otas\MicroService\Console\Commands;

use Illuminate\Console\Command;
use Otas\MicroService\Helpers\MsHttp;

class MicroServiceUpdateOriginCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ms:update-origin
                            {name : The connected service name, e.g. landlord}
                            {origin : The new origin, e.g. landlord.example.com}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the origin of a connected microservice and keep its keys';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $isUpdated = MsHttp::updateOrigin(
            microserviceName: $this->argument('name'),
            origin: $this->argument('origin'),
        );

        if (! $isUpdated) {
            $this->warn('The service "' . $this->argument('name') . '" is not connected yet. Run "php artisan ms:establish" instead.');
            return Command::FAILURE;
        }

        $this->info('Origin updated successfully');

        return Command::SUCCESS;
    }
}
