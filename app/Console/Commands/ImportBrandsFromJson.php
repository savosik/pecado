<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportBrandsFromJson extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brands:import-json';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import brands grouping and logos from JSON file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = database_path('data/brands_import.json');
        if (!file_exists($path)) {
            $this->error("File $path not found.");
            return;
        }

        $json = json_decode(file_get_contents($path), true);
        if (!$json) {
            $this->error("Invalid JSON.");
            return;
        }

        $this->info("Parsing JSON...");

        foreach ($json as $categoryData) {
            $code = $categoryData['code']; // 'our', 'other', 'liquidation'
            $categoryEnum = match ($code) {
                'our' => \App\Enums\BrandCategory::Own,
                'liquidation' => \App\Enums\BrandCategory::Liquidation,
                default => \App\Enums\BrandCategory::Other,
            };

            foreach ($categoryData['items'] as $item) {
                $parentBrand = $this->processBrand($item, $categoryEnum, null);

                if (!empty($item['children'])) {
                    foreach ($item['children'] as $childItem) {
                        $this->processBrand($childItem, $categoryEnum, $parentBrand->id);
                    }
                }
            }
        }

        $this->info("Finished importing brands and logos!");
    }

    private function processBrand(array $item, \App\Enums\BrandCategory $category, ?int $parentId): \App\Models\Brand
    {
        $name = $item['name'];
        
        $brand = \App\Models\Brand::firstOrCreate(
            ['name' => $name],
            [
                'slug' => \Illuminate\Support\Str::slug($name),
                'category' => $category,
                'parent_id' => $parentId,
                'is_featured' => $item['promoted'] ?? false,
            ]
        );

        // Update category and parent_id just in case they were created before
        $brand->update([
            'category' => $category,
            'parent_id' => $parentId,
            'is_featured' => $item['promoted'] ?? false,
        ]);

        $this->info("Processed brand: " . $brand->name);

        // Process Logo
        if (!empty($item['logo']['extra-small'])) {
            if (!$brand->hasMedia('logo')) {
                try {
                    $url = $item['logo']['extra-small'];
                    $this->info("Downloading logo for " . $brand->name . " ...");
                    $brand->addMediaFromUrl($url)->toMediaCollection('logo');
                } catch (\Exception $e) {
                    $this->error("Failed to download logo for {$brand->name}: " . $e->getMessage());
                }
            }
        }

        return $brand;
    }
}
