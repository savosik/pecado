<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateOwnBrands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brands:update-own';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set specific brands as "own" and group them by first word';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $brandsList = [
            'A-Toys by TOYFA', 'Anonymo by TOYFA', 'Beyond by Toyfa', 'Black&Red by TOYFA',
            'Candy Girl', 'Dolce by TOYFA', 'Dolls-X by TOYFA', 'Erolanta', 'Erolanta Glossy',
            'Eromantica', 'eroTEQ by TOYFA', 'Erotist Adult Toys', 'Erotist Hero', 'Erotist Libido Booster',
            'Erotist Lubricant', 'Jos', 'Juicy Pussy by TOYFA', "L'Eroina", 'Let it G', 'Lingam by TOYFA',
            'Marcus by TOYFA', 'Metal by TOYFA', 'Motorlovers by TOYFA', 'Mr.Baton by TOYFA',
            'My Babe by RealStick', "O'Play by TOYFA", 'Pecado', 'Physics by Toyfa', 'POPO Pleasure by TOYFA',
            'Qli by Flovetta', 'Qvibry', 'RealStick Brutal', 'RealStick Caliber', 'RealStick Elite',
            'RealStick Elite Mulatto', 'RealStick Nude', 'RealStick Silicone', 'RealStick Strap-On',
            'Sexus Glass', 'Sexus Men', 'Sosuсki', 'Theatre by TOYFA', 'ToDo by Toyfa', 'TOYFA Basic',
            'Waname Apparel', 'Waname D-splash', 'Waname Lubricant', 'XLover by TOYFA', 'Yovee', 'Штучки-Дрючки',
        ];

        $this->info('Starting brand updates...');

        // 1. Устанавливаем категорию own для всех этих брендов
        foreach ($brandsList as $name) {
            $brand = \App\Models\Brand::where('name', $name)->first();
            if ($brand) {
                $brand->update(['category' => \App\Enums\BrandCategory::Own]);
            }
        }
        $this->info('Categories updated to Own.');

        // 2. Группируем те, что начинаются с одного и того же первого слова
        $groupByFirstWord = [];
        foreach ($brandsList as $name) {
            $parts = explode(' ', $name);
            $firstWord = $parts[0];
            $groupByFirstWord[$firstWord][] = $name;
        }

        foreach ($groupByFirstWord as $firstWord => $names) {
            // Если брендов, начинающихся с этого слова, больше одного
            if (count($names) > 1) {
                // Создаем или находим родительский бренд по первому слову
                $parent = \App\Models\Brand::firstOrCreate(
                    ['name' => $firstWord],
                    [
                        'slug' => \Illuminate\Support\Str::slug($firstWord),
                        'category' => \App\Enums\BrandCategory::Own,
                        'is_featured' => 0,
                    ]
                );

                // Привязываем все дочерние бренды к родителю
                foreach ($names as $name) {
                    if ($name !== $firstWord) {
                        $child = \App\Models\Brand::where('name', $name)->first();
                        if ($child) {
                            $child->update(['parent_id' => $parent->id]);
                        }
                    }
                }
                $this->info("Grouped {$firstWord} brands under parent ID {$parent->id}.");
            }
        }

        $this->info('Brand updates completed.');
    }
}
