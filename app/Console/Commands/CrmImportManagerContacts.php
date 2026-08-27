<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\ManagerSheetImporter;
use App\Support\Contacts\ManagerSheetReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Перенос таблицы менеджеров (контакты, условия, комментарии по клиентам) в CRM.
 *
 * Вход — JSON, разобранный заранее по строкам: люди с ролями и телефонами,
 * условия, документы, комментарий, прогноз. Команда идемпотентна и рассчитана
 * на повторные прогоны после правок файла.
 */
class CrmImportManagerContacts extends Command
{
    protected $signature = 'crm:import-manager-contacts
        {file : JSON с разобранными строками таблицы}
        {--authors=kurochkina:b2b@pecado.ru,sukhov:opt@pecado.ru : Кто автор записей по каждой вкладке, «ключ:e-mail» через запятую}
        {--dry-run : Только показать результат, ничего не записывая}
        {--overwrite : Перезаписывать уже заполненные поля контактов и паспорта}
        {--orphans : Людей из строк, чей партнёр в базе не найден, заводить «ничьими» карточками}';

    protected $description = 'Перенести контакты, условия и комментарии из таблицы менеджеров в справочник и паспорта партнёров';

    public function handle(ManagerSheetImporter $importer): int
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

        $authors = $this->resolveAuthors();

        if ($authors === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $importer->import($document, $authors, $dryRun, (bool) $this->option('overwrite'), (bool) $this->option('orphans'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array<string, User>|null
     */
    private function resolveAuthors(): ?array
    {
        $authors = [];

        foreach (explode(',', (string) $this->option('authors')) as $pair) {
            [$key, $email] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');

            if ($key === '' || $email === '') {
                continue;
            }

            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->error("Автор «{$key}»: пользователь с e-mail «{$email}» не найден.");

                return null;
            }

            $authors[$key] = $user;
        }

        if ($authors === []) {
            $this->error('Не задан ни один автор: --authors=ключ:e-mail.');

            return null;
        }

        return $authors;
    }

    private function printReport(ManagerSheetReport $report, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? '<comment>Пробный прогон — в базу ничего не записано.</comment>' : '<info>Импорт завершён.</info>');

        $this->table(['Что', 'Сколько'], [
            ['Строк в таблице', $report->rowsTotal],
            ['Партнёров сопоставлено', $report->rowsMatched],
            ['Контактов заведено', $report->contactsCreated],
            ['Контактов дополнено', $report->contactsUpdated],
            ['Привязок создано', $report->linksCreated],
            ['Паспортов обновлено', $report->profilesUpdated],
            ['Комментариев-прогнозов', $report->commentsCreated],
            ['Ничьих контактов заведено', $report->orphansCreated],
            ['Ничьих контактов дополнено', $report->orphansUpdated],
        ]);

        if ($report->ambiguous !== []) {
            $this->newLine();
            $this->warn('Не записаны — в базе несколько партнёров с таким именем:');

            foreach ($report->ambiguous as $item) {
                $this->line(sprintf('  строка %d: %s (совпадений: %d)', $item['line'], $item['name'], $item['candidates']));
            }
        }

        if ($report->unmatched !== []) {
            $this->newLine();
            $this->warn(sprintf('Не найдены в базе — %d:', count($report->unmatched)));

            foreach ($report->unmatched as $item) {
                $this->line(sprintf('  строка %d: %s', $item['line'], $item['name']));
            }
        }

        foreach ($report->warnings as $warning) {
            $this->comment('  '.$warning);
        }
    }
}
