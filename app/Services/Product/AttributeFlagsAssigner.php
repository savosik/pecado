<?php

namespace App\Services\Product;

use App\Models\Attribute;
use Illuminate\Support\Facades\DB;

class AttributeFlagsAssigner
{
    /** @var list<string> */
    private const FLAG_COLUMNS = [
        'is_variant_forming',
        'show_on_site',
        'is_filterable',
        'show_in_export',
    ];

    /**
     * @return array{
     *   attributes_updated: int,
     *   attributes_already_correct: int,
     *   changes_by_flag: array<string, int>,
     *   missing_slugs: array<int, string>,
     * }
     */
    public function sync(): array
    {
        $config = config('attribute_flags');
        $defaults = $this->normaliseDefaults($config['defaults'] ?? []);
        $variantForming = array_flip($config['variant_forming'] ?? []);
        $filterable = array_flip($config['filterable'] ?? []);
        $hiddenInCard = array_flip($config['hidden_in_card'] ?? []);
        $hiddenInExport = array_flip($config['hidden_in_export'] ?? []);

        $knownSlugs = array_unique(array_merge(
            array_keys($variantForming),
            array_keys($filterable),
            array_keys($hiddenInCard),
            array_keys($hiddenInExport),
        ));

        $result = [
            'attributes_updated' => 0,
            'attributes_already_correct' => 0,
            'changes_by_flag' => array_fill_keys(self::FLAG_COLUMNS, 0),
            'missing_slugs' => [],
        ];

        DB::transaction(function () use ($defaults, $variantForming, $filterable, $hiddenInCard, $hiddenInExport, &$result) {
            $attributes = Attribute::all(['id', 'slug', ...self::FLAG_COLUMNS]);

            foreach ($attributes as $attr) {
                $target = [
                    'is_variant_forming' => isset($variantForming[$attr->slug])
                        ? true
                        : $defaults['is_variant_forming'],
                    'show_on_site' => isset($hiddenInCard[$attr->slug])
                        ? false
                        : $defaults['show_on_site'],
                    'is_filterable' => isset($filterable[$attr->slug])
                        ? true
                        : $defaults['is_filterable'],
                    'show_in_export' => isset($hiddenInExport[$attr->slug])
                        ? false
                        : $defaults['show_in_export'],
                ];

                $dirty = [];
                foreach (self::FLAG_COLUMNS as $col) {
                    if ((bool) $attr->{$col} !== $target[$col]) {
                        $dirty[$col] = $target[$col];
                    }
                }

                if (empty($dirty)) {
                    $result['attributes_already_correct']++;

                    continue;
                }

                foreach ($dirty as $col => $value) {
                    $attr->{$col} = $value;
                    $result['changes_by_flag'][$col]++;
                }
                $attr->save();
                $result['attributes_updated']++;
            }
        });

        $dbSlugs = Attribute::pluck('slug')->all();
        $result['missing_slugs'] = array_values(array_diff($knownSlugs, $dbSlugs));

        return $result;
    }

    /**
     * @param  array<string, bool>  $defaults
     * @return array<string, bool>
     */
    private function normaliseDefaults(array $defaults): array
    {
        $normalised = [];
        foreach (self::FLAG_COLUMNS as $col) {
            $normalised[$col] = (bool) ($defaults[$col] ?? false);
        }

        return $normalised;
    }
}
