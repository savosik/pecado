<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use Illuminate\Console\Command;

/**
 * Разовая команда для деактивации атрибутов, пришедших из 1С с именем на латинице
 * (служебные snake_case-атрибуты, которые не должны показываться на сайте).
 * По умолчанию работает в режиме dry-run, для применения — флаг --apply.
 */
class DeactivateEnglishAttributes extends Command
{
    protected $signature = 'attributes:deactivate-english
                            {--apply : Применить изменения (без флага — dry-run)}';

    protected $description = 'Деактивировать атрибуты с именем без кириллических символов';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $attributes = Attribute::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'slug']);

        $candidates = $attributes->reject(fn (Attribute $attr) => Attribute::hasCyrillicName($attr->name));

        if ($candidates->isEmpty()) {
            $this->info('Кандидатов на деактивацию не найдено.');

            return self::SUCCESS;
        }

        $this->info('Найдено атрибутов для деактивации: '.$candidates->count());
        foreach ($candidates as $attr) {
            $this->line("  [{$attr->id}] {$attr->name}  ({$attr->slug})");
        }

        if (! $apply) {
            $this->warn('[DRY RUN] Изменения не применены. Для применения запустите с флагом --apply.');

            return self::SUCCESS;
        }

        $ids = $candidates->pluck('id')->all();
        $updated = Attribute::whereIn('id', $ids)->update(['is_active' => false]);

        $this->info("Деактивировано: {$updated}.");

        return self::SUCCESS;
    }
}
