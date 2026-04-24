<?php

namespace App\Services\Product;

use App\Models\Attribute;
use App\Models\AttributeGroup;
use Illuminate\Support\Facades\DB;

class AttributeGroupAssigner
{
    /**
     * @return array{
     *   groups_created: int,
     *   groups_updated: int,
     *   attributes_assigned: int,
     *   attributes_already_correct: int,
     *   missing_slugs: array<int, string>,
     * }
     */
    public function sync(): array
    {
        $config = config('attribute_groups');
        $groupsConfig = $config['groups'] ?? [];
        $assignments = $config['assignments'] ?? [];

        $result = [
            'groups_created' => 0,
            'groups_updated' => 0,
            'attributes_assigned' => 0,
            'attributes_already_correct' => 0,
            'missing_slugs' => [],
        ];

        DB::transaction(function () use ($groupsConfig, $assignments, &$result) {
            $groupsByName = $this->syncGroups($groupsConfig, $result);
            $this->applyAssignments($assignments, $groupsByName, $result);
        });

        return $result;
    }

    /**
     * @param  array<int, array{name: string, sort_order: int}>  $groupsConfig
     * @return array<string, int> name => id
     */
    private function syncGroups(array $groupsConfig, array &$result): array
    {
        $groupsByName = [];
        foreach ($groupsConfig as $cfg) {
            $name = $cfg['name'];
            $sortOrder = $cfg['sort_order'] ?? 0;

            $group = AttributeGroup::firstOrNew(['name' => $name]);
            if (! $group->exists) {
                $group->sort_order = $sortOrder;
                $group->save();
                $result['groups_created']++;
            } elseif ((int) $group->sort_order !== (int) $sortOrder) {
                $group->sort_order = $sortOrder;
                $group->save();
                $result['groups_updated']++;
            }

            $groupsByName[$name] = $group->id;
        }

        return $groupsByName;
    }

    /**
     * @param  array<string, string>  $assignments  slug => group_name
     * @param  array<string, int>  $groupsByName
     */
    private function applyAssignments(array $assignments, array $groupsByName, array &$result): void
    {
        if (empty($assignments)) {
            return;
        }

        $slugs = array_keys($assignments);
        $attributes = Attribute::whereIn('slug', $slugs)->get(['id', 'slug', 'attribute_group_id']);
        $foundSlugs = $attributes->pluck('slug')->all();
        $result['missing_slugs'] = array_values(array_diff($slugs, $foundSlugs));

        foreach ($attributes as $attr) {
            $groupName = $assignments[$attr->slug];
            $groupId = $groupsByName[$groupName] ?? null;
            if ($groupId === null) {
                continue;
            }

            if ((int) $attr->attribute_group_id === (int) $groupId) {
                $result['attributes_already_correct']++;

                continue;
            }

            $attr->attribute_group_id = $groupId;
            $attr->save();
            $result['attributes_assigned']++;
        }
    }
}
