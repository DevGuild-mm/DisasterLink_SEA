<?php

namespace App\Console\Commands;

use App\Services\Disaster\Weather\WeatherRefreshService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('weather:refresh-capitals {--catch-up : Refresh only if the stored weather is stale and no recent attempt was made}')]
#[Description('Fetch current weather for the ASEAN capitals from Open-Meteo and store it')]
class RefreshCapitalWeather extends Command
{
    public function handle(WeatherRefreshService $weather): int
    {
        if ($this->option('catch-up')) {
            $run = $weather->catchUp();

            if ($run === null) {
                $this->components->info('No weather catch-up needed (data is recent, a recent attempt exists, or a refresh is running).');

                return self::SUCCESS;
            }
        } else {
            $run = $weather->refresh();

            if ($run === null) {
                $this->components->warn('Another weather refresh is already running, skipped.');

                return self::SUCCESS;
            }
        }

        if ($run->status === 'success') {
            $this->components->info("Weather: fetched {$run->fetched_count}, changed {$run->updated_count} ({$run->duration_ms} ms).");

            return self::SUCCESS;
        }

        $this->components->error("Weather refresh failed. {$run->error_message}");

        return self::FAILURE;
    }
}
