<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder
{
    /**
     * Seed the three MVP data sources. Existing health fields are left untouched.
     */
    public function run(): void
    {
        $now = now();

        DataSource::query()->upsert([
            ['key' => 'gdacs', 'name' => 'GDACS', 'pipeline' => 'events', 'interval_minutes' => 15, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'usgs', 'name' => 'USGS Earthquakes', 'pipeline' => 'events', 'interval_minutes' => 15, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'open_meteo', 'name' => 'Open-Meteo', 'pipeline' => 'weather', 'interval_minutes' => 30, 'created_at' => $now, 'updated_at' => $now],
        ], ['key'], ['name', 'pipeline', 'interval_minutes', 'updated_at']);
    }
}
