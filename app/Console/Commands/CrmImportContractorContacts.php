<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\ContractorSheetImporter;
use App\Support\Contacts\ContractorSheetReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Перенос таблицы «контрагенты партнёра — почты» в справочник контактов.
 *
 * Вход — JSON, разобранный из таблицы менеджера: строка на юрлицо партнёра
 * с типом работы и адресами. Команда идемпотентна: повторный прогон дополняет
 * пустые поля и не плодит ни людей, ни привязки, ни абзацы заметок.
 */
class CrmImportContractorContacts extends Command
{
    protected $signature = 'crm:import-contractor-contacts
        {file : JSON с разобранными строками таблицы контрагентов}
        {--author=opt@pecado.ru : E-mail сотрудника, от чьего имени заводятся карточки}
        {--dry-run : Только показать результат, ничего не записывая}
        {--overwrite : Перезаписывать уже заполненные поля контактов}';

    protected $description = 'Перенести контакты контрагентов партнёра (владельцы ИП, почты) в справочник';

    public function handle(ContractorSheetImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Файл «{$path}» не читается.");

            return self::FAILURE;
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document) || ! is_array($document['rows'] ?? null)) {
            $this->error('В файле нет массива rows — это не разобранная таблица.');

            return self::FAILURE;
        }

        if (blank($document['client_id'] ?? null)) {
            $this->error('В файле не указан client_id — неизвестно, чьи это контрагенты.');

            return self::FAILURE;
        }

        $author = User::query()->where('email', (string) $this->option('author'))->first();

        if ($author === null) {
            $this->error('Автор с e-mail «'.$this->option('author').'» не найден.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $importer->import($document, $author, $dryRun, (bool) $this->option('overwrite'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report, $dryRun);

        return self::SUCCESS;
    }

    private function printReport(ContractorSheetReport $report, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? '<comment>Пробный прогон — в базу ничего не записано.</comment>' : '<info>Импорт завершён.</info>');

        $this->table(['Что', 'Сколько'], [
            ['Строк в таблице', $report->rowsTotal],
            ['Контрагентов сопоставлено', $report->rowsMatched],
            ['Контактов заведено', $report->contactsCreated],
            ['Контактов дополнено', $report->contactsUpdated],
            ['Привязок создано', $report->linksCreated],
        ]);

        if ($report->sharedEmails !== []) {
            $this->newLine();
            $this->comment('Общие ящики сети — в справочник людей не заводятся, ушли в заметки:');
            $this->line('  '.implode(', ', $report->sharedEmails));
        }

        if ($report->withoutPerson !== []) {
            $this->newLine();
            $this->warn(sprintf('Человек за названием не читается — %d (нужно имя в файле):', count($report->withoutPerson)));

            foreach ($report->withoutPerson as $item) {
                $this->line(sprintf('  строка %d: %s', $item['line'], $item['contractor']));
            }
        }

        if ($report->unmatched !== []) {
            $this->newLine();
            $this->warn(sprintf('Контрагент не найден среди юрлиц партнёра — %d:', count($report->unmatched)));

            foreach ($report->unmatched as $item) {
                $this->line(sprintf('  строка %d: %s', $item['line'], $item['contractor']));
            }
        }

        if ($report->ambiguous !== []) {
            $this->newLine();
            $this->warn('Не записаны — у партнёра несколько юрлиц с таким названием:');

            foreach ($report->ambiguous as $item) {
                $this->line(sprintf('  строка %d: %s (совпадений: %d)', $item['line'], $item['contractor'], $item['candidates']));
            }
        }

        foreach ($report->warnings as $warning) {
            $this->comment('  '.$warning);
        }
    }
}
