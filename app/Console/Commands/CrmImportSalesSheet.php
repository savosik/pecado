<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Crm\CrmApiClient;
use App\Services\Crm\SalesSheetApiImporter;
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
        {--author= : E-mail сотрудника, от имени которого пишутся планы и статусы (для локальной базы)}
        {--api= : Адрес сайта для записи через агентское API, например https://pecado.ru}
        {--token= : Токен CRM-агента; надёжнее задать переменной PECADO_CRM_TOKEN}
        {--token-file= : Файл с токеном: строка или JSON рабочего места менеджера (.claude/settings.local.json)}
        {--dry-run : Только показать результат, ничего не записывая}
        {--force : Не спрашивать подтверждение перед записью в боевую CRM}
        {--overwrite : Перезаписывать заполненные поля паспорта и статусы, выставленные вручную}';

    protected $description = 'Перенести планы («корректировка») и паспорта клиентов из таблицы продаж в CRM';

    public function handle(SalesSheetReader $reader, SalesSheetImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $api = trim((string) $this->option('api'));

        // Автор в режиме API приходит вместе с токеном: сервер сам превращает
        // токен в сотрудника, и второй способ его назвать только путал бы.
        $author = $api === '' ? $this->resolveAuthor() : null;

        if ($api === '' && $author === null) {
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

        try {
            $report = $api === ''
                ? $importer->import($sheet, $author, $dryRun, $overwrite)
                : $this->importThroughApi($api, $sheet, $dryRun, $overwrite);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report === null) {
            return self::FAILURE;
        }

        $this->printReport($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Запись в боевую CRM через агентское API — путь для случая, когда доступа
     * к серверу нет, а данные внести нужно.
     */
    private function importThroughApi(string $baseUrl, \App\Support\Crm\SalesSheet $sheet, bool $dryRun, bool $overwrite): ?SalesSheetImportReport
    {
        $token = $this->resolveToken();

        if ($token === null) {
            return null;
        }

        $client = new CrmApiClient($baseUrl, $token);
        $me = $client->me();

        $actor = $me['data']['actor'] ?? [];
        $scope = $me['data']['scope'] ?? [];
        $seesAll = (bool) ($scope['sees_all'] ?? false);

        $this->info(sprintf(
            'Записи пойдут от имени: %s <%s> на %s.',
            $actor['name'] ?? 'неизвестно',
            $actor['email'] ?? '—',
            $baseUrl,
        ));

        $this->line(sprintf(
            'Видит клиентов: %s. Планы менеджеров и отдела: %s.',
            $scope['clients_visible'] ?? '?',
            $seesAll ? 'доступны' : 'НЕДОСТУПНЫ — сервер их пропустит',
        ));

        // Без права видеть весь отдел сервер молча отбросит планы чужих клиентов,
        // менеджеров и отдела. Молча — это и есть проблема: отчёт покажет успех,
        // а в CRM не появится почти ничего.
        if (! $seesAll) {
            $this->warn('У сотрудника нет права «видеть всех клиентов»: запишутся только планы его собственных клиентов.');
        }

        // Подтверждение спрашивается всегда, кроме явного --force: записи необратимы,
        // и «случайно запустил не тот файл» здесь стоит дороже одного вопроса.
        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Записать данные в эту CRM?', false)) {
            $this->comment('Отменено.');

            return null;
        }

        return (new SalesSheetApiImporter($client))->import($sheet, $dryRun, $overwrite);
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

    /**
     * Токен CRM-агента: из файла, из окружения или из аргумента.
     *
     * Файл и переменная окружения предпочтительнее аргумента: значение, переданное
     * командной строкой, видно в списке процессов и оседает в истории оболочки, а
     * этим токеном пишут в боевую CRM от имени живого сотрудника.
     */
    private function resolveToken(): ?string
    {
        $path = trim((string) $this->option('token-file'));

        if ($path !== '') {
            $token = $this->tokenFromFile($path);

            if ($token === null) {
                $this->error("Не удалось прочитать токен из файла «{$path}».");
            }

            return $token;
        }

        // getenv, а не env(): токен приходит из окружения запуска, а не из
        // конфигурации приложения, и при закешированном конфиге env() вернул бы null.
        $token = trim((string) ($this->option('token') ?: getenv('PECADO_CRM_TOKEN')));

        if ($token === '') {
            $this->error('Нужен токен CRM-агента: передайте --token-file, переменную PECADO_CRM_TOKEN или --token.');

            return null;
        }

        return $token;
    }

    /**
     * Токен из файла: либо строкой, либо из рабочего места менеджера, где он лежит
     * в блоке `env` файла `.claude/settings.local.json`.
     */
    private function tokenFromFile(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        if ($contents === '') {
            return null;
        }

        if (! str_starts_with($contents, '{')) {
            return $contents;
        }

        $json = json_decode($contents, true);

        if (! is_array($json)) {
            return null;
        }

        $token = $json['env']['PECADO_CRM_TOKEN'] ?? $json['PECADO_CRM_TOKEN'] ?? null;

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
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
