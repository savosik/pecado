<?php

namespace App\Console\Commands;

use App\Services\Product\AttributeGroupAssigner;
use Illuminate\Console\Command;

/**
 * Создаёт группы атрибутов из config/attribute_groups.php и раскладывает
 * существующие атрибуты по группам по совпадению slug.
 *
 * Идемпотентна: повторный запуск не создаёт дубликатов и не трогает уже
 * корректно назначенные атрибуты. Атрибуты без маппинга в конфиге не меняются.
 */
class AttributesAssignGroups extends Command
{
    protected $signature = 'attributes:assign-groups';

    protected $description = 'Создать группы атрибутов и распределить атрибуты по группам согласно config/attribute_groups.php';

    public function handle(AttributeGroupAssigner $assigner): int
    {
        $this->info('Синхронизация групп атрибутов...');

        $result = $assigner->sync();

        $this->newLine();
        $this->info('Группы:');
        $this->line('  создано: '.$result['groups_created']);
        $this->line('  обновлено (sort_order): '.$result['groups_updated']);

        $this->newLine();
        $this->info('Атрибуты:');
        $this->line('  назначено в группу: '.$result['attributes_assigned']);
        $this->line('  уже в правильной группе: '.$result['attributes_already_correct']);

        if (! empty($result['missing_slugs'])) {
            $this->newLine();
            $this->warn('Slug-и из конфига, не найденные в БД: '.count($result['missing_slugs']));
            foreach ($result['missing_slugs'] as $slug) {
                $this->line('  - '.$slug);
            }
            $this->line('Эти атрибуты будут привязаны автоматически при следующем запуске, когда появятся в БД.');
        }

        $this->newLine();
        $this->info('Готово.');

        return self::SUCCESS;
    }
}
