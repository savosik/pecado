<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

/**
 * Разовая диагностика Spatie-тегов: показать все записи, raw-значения translatable-полей
 * и количество связей. Помогает поймать теги с пустым `name` (артефакты импорта / битые данные),
 * из-за которых на публичных страницах отображались пустые chip-ы.
 *
 * Флаг --purge-empty удаляет теги без переводов `name` вместе с их связями в `taggables`.
 */
class AuditTags extends Command
{
    protected $signature = 'tags:audit {--purge-empty : Удалить теги без переводов name вместе со связями}';

    protected $description = 'Диагностика Spatie-тегов и (опционально) очистка битых записей';

    public function handle(): int
    {
        $tags = Tag::query()->orderBy('id')->get();

        $rows = $tags->map(function (Tag $tag): array {
            $nameRaw = $tag->getRawOriginal('name');
            $slugRaw = $tag->getRawOriginal('slug');

            return [
                'id' => $tag->id,
                'type' => $tag->type,
                'name_raw' => $this->preview($nameRaw),
                'slug_raw' => $this->preview($slugRaw),
                'empty' => $this->isEmptyTranslations($nameRaw) ? 'да' : '',
                'taggables' => DB::table('taggables')->where('tag_id', $tag->id)->count(),
            ];
        });

        $this->table(
            ['id', 'type', 'name_raw', 'slug_raw', 'empty?', 'links'],
            $rows->map(fn ($r) => array_values($r))->all(),
        );

        $emptyTags = $tags->filter(fn (Tag $t) => $this->isEmptyTranslations($t->getRawOriginal('name')));

        $this->info("Всего тегов: {$tags->count()}, битых (без переводов name): {$emptyTags->count()}");

        if (! $this->option('purge-empty')) {
            $this->line('Для удаления битых запусти: php artisan tags:audit --purge-empty');

            return self::SUCCESS;
        }

        if ($emptyTags->isEmpty()) {
            $this->info('Нечего удалять.');

            return self::SUCCESS;
        }

        $ids = $emptyTags->pluck('id')->all();
        $links = DB::table('taggables')->whereIn('tag_id', $ids)->delete();
        $deleted = Tag::query()->whereIn('id', $ids)->delete();

        $this->info("Удалено связей taggables: {$links}, тегов: {$deleted}");

        return self::SUCCESS;
    }

    private function preview(mixed $value): string
    {
        if ($value === null) {
            return '(null)';
        }

        $string = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        return mb_strimwidth($string ?? '', 0, 80, '…');
    }

    private function isEmptyTranslations(mixed $raw): bool
    {
        if ($raw === null || $raw === '' || $raw === '[]' || $raw === '{}') {
            return true;
        }

        if (! is_string($raw)) {
            return false;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || $decoded === []) {
            return true;
        }

        foreach ($decoded as $value) {
            if (is_string($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
