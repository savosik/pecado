<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Crm\SalesSheetImporter;
use App\Services\Crm\SalesSheetReader;
use App\Support\Crm\SalesSheetImportReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Импорт управленческой таблицы продаж в CRM.
 *
 * Таблицу ведёт руководитель отдела, и она остаётся источником плана: команда
 * рассчитана на повторные запуски после каждой правки корректировок. Поэтому
 * планы перезаписываются, а паспорт клиента по умолчанию только дополняется —
 * затирать уточнения менеджеров при регулярном прогоне было бы разрушительно.
 */
class CrmImportSalesSheet extends Command
{
    protected $signature = 'crm:import-sales-sheet
        {file : Путь к XLSX-выгрузке таблицы продаж}
        {--sheet=ОПТ действующие : Лист с клиентами}
        {--author= : E-mail сотрудника, от имени которого записываются планы и статусы}
        {--dry-run : Только показать результат, ничего не записывая}
        {--overwrite : Перезаписывать заполненные поля паспорта и статусы, выставленные вручную}';

    protected $description = 'Перенести планы («корректировка») и паспорта клиентов из таблицы продаж в CRM';

    public function handle(SalesSheetReader $reader, SalesSheetImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $author = $this->resolveAuthor();

        if ($author === null) {
            return self::FAILURE;
        }

        try {
            $sheet = $reader->read((string) $this->argument('file'), (string) $this->option('sheet'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Прочитано: клиентов — %d, строк «новые клиенты» — %d, месяцев плана отдела — %d.',
            count($sheet->clients()),
            count($sheet->newClientBuckets()),
            count($sheet->departmentPlans),
        ));

        $report = $importer->import($sheet, $author, $dryRun, (bool) $this->option('overwrite'));

        $this->printReport($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Автор нужен и планам, и журналу смены статусов: «кто поставил» — это
     * половина смысла записи, поэтому подставить служебную заглушку нельзя.
     */
    private function resolveAuthor(): ?User
    {
        $email = trim((string) $this->option('author'));

        if ($email === '') {
            $this->error('Укажите --author=e-mail сотрудника: планы и смены статусов записываются от его имени.');

            return null;
        }

        $author = User::query()->where('email', $email)->first();

        if ($author === null) {
            $this->error("Пользователь с e-mail «{$email}» не найден.");
        }

        return $author;
    }

    private function printReport(SalesSheetImportReport $report, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? '<comment>Пробный прогон — в базу ничего не записано.</comment>' : '<info>Импорт завершён.</info>');

        $this->table(['Что', 'Сколько'], [
            ['Клиентов сопоставлено', $report->clientsMatched],
            ['Планов клиентов записано', $report->plansSaved],
            ['Паспортов обновлено', $report->profilesUpdated],
            ['Статусов изменено', $report->statusesChanged],
            ['Планов менеджеров записано', $report->managerPlansSaved],
            ['Планов отдела записано', $report->departmentPlansSaved],
        ]);

        if ($report->ambiguous !== []) {
            $this->newLine();
            $this->warn('Не записаны — в базе несколько клиентов с таким именем:');

            foreach ($report->ambiguous as $item) {
                $this->line(sprintf('  строка %d: %s (совпадений: %d)', $item['line'], $item['name'], $item['candidates']));
            }
        }

        if ($report->unmatched !== []) {
            $this->newLine();
            $this->warn(sprintf('Не найдены в базе — %d:', count($report->unmatched)));

            foreach ($report->unmatched as $item) {
                $this->line(sprintf(
                    '  строка %d: %s%s',
                    $item['line'],
                    $item['name'],
                    $item['amount'] > 0 ? sprintf(' — план %s ₽', number_format($item['amount'], 0, ',', ' ')) : '',
                ));
            }

            $this->newLine();
            $this->warn(sprintf(
                'Итого не перенесено планов на %s ₽ — план отдела и менеджеров на эту сумму окажется больше суммы клиентских.',
                number_format($report->lostAmount, 0, ',', ' '),
            ));
        }

        if ($report->warnings !== []) {
            $this->newLine();
            $this->comment('Замечания по разбору таблицы:');

            foreach ($report->warnings as $warning) {
                $this->line('  '.$warning);
            }
        }
    }
}
