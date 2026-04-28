<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Пакетно ставит в очередь генерацию conversion `large` для медиа товаров
 * (коллекции main + additional). Используется один раз после релиза, чтобы
 * прогреть существующие 20–25к изображений; новые медиа получают large
 * автоматически при загрузке.
 */
class RegenerateProductLargeConversions extends Command
{
    protected $signature = 'catalog:regenerate-product-large
        {--only-missing : Пропускать медиа, у которых large уже сгенерирована}
        {--chunk=500 : Размер пачки при обходе media}
        {--dry-run : Только посчитать сколько медиа будет обработано}
        {--queue-all : Принудительно queued (на случай нестандартной конфигурации)}';

    protected $description = 'Пакетно регенерирует conversion `large` у изображений товаров через очередь media-conversions';

    public function handle(FileManipulator $fileManipulator): int
    {
        $onlyMissing = (bool) $this->option('only-missing');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $queueAll = (bool) $this->option('queue-all');

        $query = Media::query()
            ->where('model_type', (new Product)->getMorphClass())
            ->whereIn('collection_name', ['main', 'additional']);

        if ($onlyMissing) {
            $query->where(function ($q) {
                $q->whereNull('generated_conversions')
                    ->orWhereJsonDoesntContain('generated_conversions->large', true);
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Нечего обрабатывать — медиа товаров не найдено (или уже всё сгенерировано).');

            return self::SUCCESS;
        }

        $this->info("Будет обработано медиа: {$total}".($onlyMissing ? ' (только без large)' : ''));

        if ($dryRun) {
            $this->line('Dry-run: показываю первые 20 кандидатов и выхожу.');
            $query->limit(20)->get(['id', 'model_id', 'collection_name', 'file_name'])->each(
                fn (Media $m) => $this->line("  #{$m->id}\tproduct={$m->model_id}\t{$m->collection_name}\t{$m->file_name}")
            );

            return self::SUCCESS;
        }

        $queue = config('media-library.queue_name') ?: '(default)';
        $this->info("Диспатчу PerformConversionsJob в очередь: {$queue}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($chunk) use ($fileManipulator, $onlyMissing, $queueAll, $bar) {
            foreach ($chunk as $media) {
                $fileManipulator->createDerivedFiles(
                    $media,
                    ['large'],
                    $onlyMissing,
                    false,
                    $queueAll,
                );
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Готово. Следи за очередью: docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep media-conversions');

        return self::SUCCESS;
    }
}
