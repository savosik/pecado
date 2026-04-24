<?php

namespace App\Console\Commands;

use App\Services\Product\AttributeFlagsAssigner;
use Illuminate\Console\Command;

/**
 * Расставляет атрибутам флаги видимости из config/attribute_flags.php:
 *  - is_variant_forming
 *  - show_on_site
 *  - is_filterable
 *  - show_in_export
 *
 * Идемпотентна: конфиг — источник правды, при повторном запуске меняет только
 * те значения, что не совпадают с целевыми. Атрибуты, не упомянутые в списках,
 * получают значения из `defaults`.
 */
class AttributesAssignFlags extends Command
{
    protected $signature = 'attributes:assign-flags';

    protected $description = 'Расставить атрибутам флаги (вариантообразующий, карточка, фильтр, выгрузка) по config/attribute_flags.php';

    public function handle(AttributeFlagsAssigner $assigner): int
    {
        $this->info('Синхронизация флагов атрибутов...');

        $result = $assigner->sync();

        $this->newLine();
        $this->info('Атрибуты:');
        $this->line('  обновлено: '.$result['attributes_updated']);
        $this->line('  уже в корректном состоянии: '.$result['attributes_already_correct']);

        $this->newLine();
        $this->info('Изменений по флагам:');
        foreach ($result['changes_by_flag'] as $flag => $count) {
            $this->line('  '.str_pad($flag, 20).': '.$count);
        }

        if (! empty($result['missing_slugs'])) {
            $this->newLine();
            $this->warn('Slug-и из конфига, не найденные в БД: '.count($result['missing_slugs']));
            foreach ($result['missing_slugs'] as $slug) {
                $this->line('  - '.$slug);
            }
        }

        $this->newLine();
        $this->info('Готово.');

        return self::SUCCESS;
    }
}
