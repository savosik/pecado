<?php

namespace App\Console\Commands;

use App\Services\Logistics\DeliveryAddressImporter;
use App\Services\Logistics\ShipmentSheetReader;
use App\Support\Logistics\DeliveryAddressImportReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Перенос адресов доставки из таблицы заданий логисту в кабинеты клиентов.
 *
 * Команда рассчитана на повторные запуски: адреса, которые уже есть в кабинете,
 * не дублируются, а ничего не удаляется и не перезаписывается — клиент правит
 * свой справочник сам, и импорт не должен затирать его правки.
 */
class ImportLogisticsAddresses extends Command
{
    protected $signature = 'logistics:import-addresses
        {file : Путь к XLSX-выгрузке таблицы заданий логисту}
        {--sheets=2025 год,2026 год : Листы через запятую — по одному на год}
        {--max-per-client=10 : Сколько адресов максимум держать в одном кабинете}
        {--dry-run : Только показать, что будет добавлено}
        {--force : Не спрашивать подтверждение перед записью}
        {--report= : Куда сохранить CSV со списком добавленных адресов}';

    protected $description = 'Заполнить справочник адресов доставки в кабинетах по таблице логиста';

    public function handle(ShipmentSheetReader $reader, DeliveryAddressImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sheets = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('sheets')))));

        try {
            $rows = $reader->read((string) $this->argument('file'), $sheets);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Прочитано строк отгрузок: %d (листы: %s).', count($rows), implode(', ', $sheets)));

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Записать адреса в кабинеты клиентов?', false)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        try {
            $report = $importer->import($rows, $dryRun, max(1, (int) $this->option('max-per-client')));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report, $dryRun);

        $path = trim((string) $this->option('report'));

        if ($path !== '') {
            $this->writeCsv($report, $path);
            $this->line("Отчёт сохранён: {$path}");
        }

        return self::SUCCESS;
    }

    private function printReport(DeliveryAddressImportReport $report, bool $dryRun): void
    {
        $this->newLine();
        $this->table(['Показатель', 'Значение'], [
            ['Строк в таблице', $report->rowsRead],
            ['Опознано по ИНН', $report->matchedByTaxId],
            ['Опознано по названию', $report->matchedByName],
            ['Без адреса', $report->rowsWithoutAddress],
            ['Самовывоз', $report->rowsSelfPickup],
            ['Контрагент не опознан (строк)', count($report->unmatched)],
            ['Неоднозначный контрагент (строк)', count($report->ambiguous)],
            [$dryRun ? 'Будет добавлено адресов' : 'Добавлено адресов', $report->addressesCreated],
            ['Клиентов затронуто', $report->clientsTouched()],
            ['Уже были в кабинете', $report->addressesAlreadyPresent],
            ['Отсечено лимитом на кабинет', $report->addressesTrimmed],
            ['DaData не ответила (адресов)', $report->daDataFailures],
        ]);

        $unmatched = $report->unmatchedClients();

        if ($unmatched !== []) {
            $this->newLine();
            $this->warn('Не опознаны (топ-15 контрагентов, строк):');

            foreach (array_slice($unmatched, 0, 15, true) as $client => $count) {
                $this->line(sprintf('  %4d  %s', $count, $client));
            }
        }

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }
    }

    private function writeCsv(DeliveryAddressImportReport $report, string $path): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Не удалось открыть файл для отчёта: {$path}");

            return;
        }

        // BOM — иначе Excel открывает кириллицу вопросительными знаками.
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, ['user_id', 'клиент', 'название', 'адрес', 'перевозчик', 'отгрузок', 'последний год', 'опознан', 'разобран DaData'], ';');

        foreach ($report->created as $row) {
            fputcsv($handle, [
                $row['user_id'],
                $row['user'],
                $row['name'],
                $row['address'],
                $row['carrier'] ?? '',
                $row['shipments'],
                $row['last_year'],
                $row['matched_by'] === 'inn' ? 'по ИНН' : 'по названию',
                $row['parsed'] ? 'да' : 'нет',
            ], ';');
        }

        fclose($handle);
    }
}
