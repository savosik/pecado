<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportSexOptIds extends Command
{
    protected $signature = 'catalog:import-sex-opt-ids
        {--url= : URL CSV-эндпоинта (uuid;id), по умолчанию /682}
        {--dry-run : Показать что будет обновлено, без записи в БД}';

    protected $description = 'Импорт sex_opt_id товаров из CSV (uuid;id) — match по external_id (uuid)';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: 'https://customers.sex-opt.ru/api/public/export/682?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $this->info("Загрузка CSV: {$url}");

        $response = Http::timeout(120)->get($url);
        if (! $response->successful()) {
            $this->error("Ошибка загрузки. HTTP {$response->status()}");

            return self::FAILURE;
        }

        $rows = $this->parseCsv($response->body());
        if ($rows === null) {
            return self::FAILURE;
        }

        $this->info('Получено строк: '.count($rows));

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skippedNotFound = 0;
        $skippedSame = 0;

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, 500) as $chunk) {
            $uuids = array_column($chunk, 'uuid');

            $products = Product::query()
                ->withoutGlobalScope(HiddenScope::class)
                ->whereIn('external_id', $uuids)
                ->get(['id', 'external_id', 'sex_opt_id'])
                ->keyBy('external_id');

            foreach ($chunk as $row) {
                $bar->advance();
                $product = $products->get($row['uuid']);
                if (! $product) {
                    $skippedNotFound++;

                    continue;
                }
                if ((string) $product->sex_opt_id === (string) $row['id']) {
                    $skippedSame++;

                    continue;
                }
                if (! $dryRun) {
                    $product->sex_opt_id = $row['id'];
                    $product->saveQuietly();
                }
                $updated++;
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info("Обновлено: {$updated}");
        $this->line("Уже актуально: {$skippedSame}");
        $this->line("Не найдено по external_id: {$skippedNotFound}");
        if ($dryRun) {
            $this->warn('DRY RUN — изменения в БД не применялись');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{uuid: string, id: string}>|null
     */
    private function parseCsv(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            $this->error('Пустой ответ');

            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $body);
        $header = array_map('trim', explode(';', array_shift($lines)));
        $uuidIdx = array_search('uuid', $header, true);
        $idIdx = array_search('id', $header, true);
        if ($uuidIdx === false || $idIdx === false) {
            $this->error('CSV не содержит колонок uuid;id (получено: '.implode(';', $header).')');

            return null;
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = explode(';', $line);
            $uuid = trim($cols[$uuidIdx] ?? '');
            $id = trim($cols[$idIdx] ?? '');
            if ($uuid === '' || $id === '') {
                continue;
            }
            $rows[] = ['uuid' => $uuid, 'id' => $id];
        }

        return $rows;
    }
}
