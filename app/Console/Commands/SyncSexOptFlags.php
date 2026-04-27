<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncSexOptFlags extends Command
{
    protected $signature = 'products:sync-sex-opt-flags
        {--url=https://customers.sex-opt.ru/api/public/export/685?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp : URL экспорта sex-opt.ru}
        {--dry-run : Не вносить изменения, только показать статистику}';

    protected $description = 'Сбросить флажки товаров и обновить created_at + флажки по экспорту sex-opt.ru';

    public function handle(): int
    {
        $url = (string) $this->option('url');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Скачиваю экспорт: {$url}");

        $response = Http::timeout(180)->retry(3, 2000)->get($url);
        if (! $response->successful()) {
            $this->error("Ошибка запроса: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $items = $response->json();
        if (! is_array($items)) {
            $this->error('Некорректный формат ответа: ожидается массив объектов.');

            return self::FAILURE;
        }

        $this->info('Получено записей: '.count($items));

        if ($dryRun) {
            $this->warn('--dry-run: изменений не будет.');
        } else {
            $this->info('Сбрасываю флажки у всех товаров...');
            $reset = DB::table('products')->update([
                'is_new' => false,
                'is_bestseller' => false,
                'is_marked' => false,
                'is_liquidation' => false,
                'for_marketplaces' => false,
            ]);
            $this->info("Флажки сброшены у {$reset} записей.");
        }

        $bar = $this->output->createProgressBar(count($items));
        $bar->start();

        $updated = 0;
        $missing = 0;
        $invalid = 0;

        foreach ($items as $item) {
            $bar->advance();

            $uid = $item['uid'] ?? null;
            if (! $uid) {
                $invalid++;

                continue;
            }

            $createdAt = null;
            $rawDate = $item['created_at'] ?? null;
            if ($rawDate) {
                try {
                    $createdAt = Carbon::createFromFormat('d.m.Y H:i:s', $rawDate);
                } catch (\Throwable $e) {
                    $invalid++;
                }
            }

            $payload = [
                'is_new' => (bool) ($item['is_new'] ?? false),
                'is_bestseller' => (bool) ($item['is_bestseller'] ?? false),
                'is_marked' => (bool) ($item['is_marked'] ?? false),
                'is_liquidation' => (bool) ($item['is_liquidation'] ?? false),
                'for_marketplaces' => (bool) ($item['for_marketplaces'] ?? false),
            ];

            if ($createdAt) {
                $payload['created_at'] = $createdAt;
            }

            if ($dryRun) {
                continue;
            }

            $affected = DB::table('products')
                ->where('external_id', $uid)
                ->update($payload);

            if ($affected) {
                $updated++;
            } else {
                $missing++;
            }
        }

        $bar->finish();
        $this->newLine();

        $this->table(
            ['Обновлено', 'Не найдено', 'Некорректных записей'],
            [[$updated, $missing, $invalid]],
        );

        return self::SUCCESS;
    }
}
