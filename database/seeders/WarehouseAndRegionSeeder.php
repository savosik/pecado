<?php

namespace Database\Seeders;

use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseAndRegionSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Регионы ───
        $regions = [
            ['name' => 'Москва'],
        ];

        foreach ($regions as $data) {
            Region::firstOrCreate(['name' => $data['name']]);
        }

        $this->command->info('Создано/найдено регионов: '.count($regions));

        // ─── Склады ───
        $warehouses = [
            [
                'name' => 'Москва Основной',
                'external_id' => '40301d16-3847-11e1-8034-001e6711ed1d',
            ],
        ];

        foreach ($warehouses as $data) {
            Warehouse::updateOrCreate(
                ['external_id' => $data['external_id']],
                ['name' => $data['name']]
            );
        }

        $this->command->info('Создано/найдено складов: '.count($warehouses));
    }
}
