<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class CountrySeeder extends Seeder
{
    /**
     * Seed the 11 ASEAN countries. Safe to run repeatedly (upserts by iso2).
     */
    public function run(): void
    {
        $now = now();

        $rows = collect([
            ['BN', 'BRN', 'Brunei Darussalam', 'Asia/Brunei', 'Bandar Seri Begawan', 4.9031, 114.9398],
            ['KH', 'KHM', 'Cambodia', 'Asia/Phnom_Penh', 'Phnom Penh', 11.5564, 104.9282],
            ['ID', 'IDN', 'Indonesia', 'Asia/Jakarta', 'Jakarta', -6.2088, 106.8456],
            ['LA', 'LAO', 'Laos', 'Asia/Vientiane', 'Vientiane', 17.9757, 102.6331],
            ['MY', 'MYS', 'Malaysia', 'Asia/Kuala_Lumpur', 'Kuala Lumpur', 3.1390, 101.6869],
            ['MM', 'MMR', 'Myanmar', 'Asia/Yangon', 'Naypyidaw', 19.7633, 96.0785],
            ['PH', 'PHL', 'Philippines', 'Asia/Manila', 'Manila', 14.5995, 120.9842],
            ['SG', 'SGP', 'Singapore', 'Asia/Singapore', 'Singapore', 1.3521, 103.8198],
            ['TH', 'THA', 'Thailand', 'Asia/Bangkok', 'Bangkok', 13.7563, 100.5018],
            ['TL', 'TLS', 'Timor-Leste', 'Asia/Dili', 'Dili', -8.5569, 125.5788],
            ['VN', 'VNM', 'Vietnam', 'Asia/Ho_Chi_Minh', 'Hanoi', 21.0285, 105.8542],
        ])->map(fn (array $row, int $index) => [
            'iso2' => $row[0],
            'iso3' => $row[1],
            'name' => $row[2],
            'timezone' => $row[3],
            'capital_name' => $row[4],
            'capital_lat' => $row[5],
            'capital_lng' => $row[6],
            'sort_order' => $index + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        Country::query()->upsert(
            $rows,
            ['iso2'],
            ['iso3', 'name', 'timezone', 'capital_name', 'capital_lat', 'capital_lng', 'sort_order', 'is_active', 'updated_at'],
        );

        Cache::forget('locations:countries');
    }
}
