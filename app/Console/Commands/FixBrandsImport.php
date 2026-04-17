<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FixBrandsImport extends Command
{
    protected $signature = 'app:fix-brands-import';

    protected $description = 'Merge mistaken empty parent brands logos to original brands and delete empty ones';

    public function handle()
    {
        $cutoffDate = '2026-03-24 00:00:00';

        $emptyParents = Brand::where('created_at', '>=', $cutoffDate)
            ->whereNull('parent_id')
            ->doesntHave('products')
            ->get();

        foreach ($emptyParents as $emptyP) {
            $this->info("Checking new empty parent: {$emptyP->name} (ID: {$emptyP->id})");

            // Look for an original brand that has products and matches the name
            $nameLower = Str::lower($emptyP->name);
            $nameWithoutDash = str_replace('-', ' ', $nameLower);

            $matchingOriginals = Brand::where('id', '!=', $emptyP->id)
                ->where('created_at', '<', $cutoffDate)
                ->where(function ($q) use ($emptyP, $nameWithoutDash) {
                    $q->where('name', 'LIKE', '%'.$emptyP->name.'%')
                        ->orWhere('name', 'LIKE', '%'.$nameWithoutDash.'%')
                        ->orWhere('name', 'LIKE', '%'.str_replace(' ', '-', $emptyP->name).'%');
                })
                ->get();

            if ($matchingOriginals->count() === 1) {
                $original = $matchingOriginals->first();
                $this->info("  -> Matched original: {$original->name} (ID: {$original->id})");

                $logo = $emptyP->getFirstMedia('logo');
                if ($logo) {
                    $logo->model_type = get_class($original);
                    $logo->model_id = $original->id;
                    $logo->save();
                    $this->info('     [OK] Logo transferred.');
                }

                Brand::where('parent_id', $emptyP->id)->update(['parent_id' => $original->id]);

                $emptyP->delete();
                $this->info('     [OK] Deleted empty parent.');
            } elseif ($matchingOriginals->count() > 1) {
                $this->warn("  -> Multiple matches found for {$emptyP->name}: ".$matchingOriginals->pluck('name')->join(', '));
            } else {
                // If it's literally just Anonymo vs Anonymo by TOYFA, but they didn't match?
                // Maybe the creation date check failed? Let's check without cutoff date just in case it was created differently
                $fallback = Brand::where('id', '!=', $emptyP->id)
                    ->has('products')
                    ->where('name', 'LIKE', '%'.$emptyP->name.'%')
                    ->first();

                if ($fallback) {
                    $original = $fallback;
                    $this->info("  -> Fallback matched original (has products): {$original->name} (ID: {$original->id})");

                    $logo = $emptyP->getFirstMedia('logo');
                    if ($logo) {
                        $logo->model_type = get_class($original);
                        $logo->model_id = $original->id;
                        $logo->save();
                        $this->info('     [OK] Logo transferred.');
                    }
                    Brand::where('parent_id', $emptyP->id)->update(['parent_id' => $original->id]);
                    $emptyP->delete();
                } else {
                    $this->warn("  -> NO MATCH FOUND FOR {$emptyP->name}");
                }
            }
        }

        $emptyChildren = Brand::where('created_at', '>=', $cutoffDate)
            ->whereNotNull('parent_id')
            ->doesntHave('products')
            ->get();

        foreach ($emptyChildren as $emptyC) {
            $parentName = $emptyC->parent ? $emptyC->parent->name : '';
            $guessName = trim($parentName.' '.$emptyC->name);
            $this->info("Checking new empty child: {$emptyC->name} (guessed original: {$guessName})");

            $matchingOriginals = Brand::where('id', '!=', $emptyC->id)
                ->where('created_at', '<', $cutoffDate)
                ->where('name', 'LIKE', '%'.$guessName.'%')
                ->get();

            if ($matchingOriginals->count() === 1) {
                $original = $matchingOriginals->first();
                $this->info("  -> Matched original child: {$original->name} (ID: {$original->id})");

                $logo = $emptyC->getFirstMedia('logo');
                if ($logo) {
                    $logo->model_type = get_class($original);
                    $logo->model_id = $original->id;
                    $logo->save();
                    $this->info('     [OK] Logo transferred.');
                }

                $emptyC->delete();
                $this->info('     [OK] Deleted empty child.');
            } else {
                $fallback = Brand::where('id', '!=', $emptyC->id)
                    ->has('products')
                    ->where('name', 'LIKE', '%'.$guessName.'%')
                    ->first();

                if ($fallback) {
                    $original = $fallback;
                    $this->info("  -> Fallback matched original child (has products): {$original->name}");
                    $logo = $emptyC->getFirstMedia('logo');
                    if ($logo) {
                        $logo->model_type = get_class($original);
                        $logo->model_id = $original->id;
                        $logo->save();
                        $this->info('     [OK] Logo transferred.');
                    }
                    $emptyC->delete();
                } else {
                    if (! $emptyC->hasMedia('logo')) {
                        $emptyC->delete();
                        $this->info('     [OK] Deleted empty child (no logo, no context).');
                    } else {
                        $this->warn("  -> NO MATCH FOR CHILD WITH LOGO: {$emptyC->name}");
                    }
                }
            }
        }

        $this->info('Done resolving brand duplicates and missing logos. Clearing cache...');
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
    }
}
