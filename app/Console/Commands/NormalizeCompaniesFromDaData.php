<?php

namespace App\Console\Commands;

use App\Enums\Country;
use App\Models\Company;
use App\Services\DaData\DaDataClient;
use App\Services\DaData\DaDataException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizeCompaniesFromDaData extends Command
{
    protected $signature = 'companies:normalize-dadata
                            {--id=* : ID компаний для обработки}
                            {--inn=* : ИНН компаний для обработки}
                            {--country=RU : Фильтр по стране (DaData ищет только по российскому ЕГРЮЛ)}
                            {--mode=fill-empty : fill-empty | normalize-names | full}
                            {--limit= : Максимум компаний за запуск}
                            {--sleep=0 : Пауза между запросами в секундах (для защиты лимита, дробное ОК)}
                            {--dry-run : Без записи в БД, только показать diff}';

    protected $description = 'Нормализует поля контрагентов через DaData по их ИНН';

    private const MODE_FILL_EMPTY = 'fill-empty';

    private const MODE_NORMALIZE_NAMES = 'normalize-names';

    private const MODE_FULL = 'full';

    public function handle(DaDataClient $client): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, [self::MODE_FILL_EMPTY, self::MODE_NORMALIZE_NAMES, self::MODE_FULL], true)) {
            $this->error("Недопустимое значение --mode: {$mode}. Допустимо: fill-empty | normalize-names | full");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleep = (float) $this->option('sleep');
        $country = (string) $this->option('country');

        $companies = $this->buildQuery()->get();

        if ($companies->isEmpty()) {
            $this->info('Подходящих компаний не найдено.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Обработка %d компаний (страна=%s, режим=%s%s).',
            $companies->count(),
            $country,
            $mode,
            $dryRun ? ', DRY-RUN' : '',
        ));

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'no_changes' => 0,
            'not_found' => 0,
            'invalid_inn' => 0,
            'errors' => 0,
        ];

        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        foreach ($companies as $company) {
            /** @var Company $company */
            $stats['processed']++;
            $inn = trim((string) $company->getAttribute('tax_id'));

            if (! preg_match('/^\d{10}$|^\d{12}$/', $inn)) {
                $stats['invalid_inn']++;
                $bar->advance();

                continue;
            }

            try {
                $party = $client->findPartyByInn($inn, $company->getAttribute('tax_code') ?: null);
            } catch (DaDataException $e) {
                $stats['errors']++;
                Log::warning('DaData нормализация: ошибка запроса', [
                    'company_id' => $company->getKey(),
                    'inn' => $inn,
                    'error' => $e->getMessage(),
                ]);
                $bar->advance();

                continue;
            }

            if ($party === null) {
                $stats['not_found']++;
                $bar->advance();

                continue;
            }

            $changes = $this->buildChanges($company, $party, $mode);

            if (empty($changes)) {
                $stats['no_changes']++;
                $bar->advance();

                continue;
            }

            $stats['updated']++;

            if ($dryRun) {
                $bar->clear();
                $this->renderDiff($company, $changes);
                $bar->display();
            } else {
                $company->fill($changes)->save();
            }

            $bar->advance();

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано', $stats['processed']],
                ['Обновлено'.($dryRun ? ' (dry-run)' : ''), $stats['updated']],
                ['Без изменений (актуально)', $stats['no_changes']],
                ['Не найдено в DaData', $stats['not_found']],
                ['Невалидный ИНН (пропущено)', $stats['invalid_inn']],
                ['Ошибок запроса', $stats['errors']],
            ],
        );

        return self::SUCCESS;
    }

    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $ids = (array) $this->option('id');
        $inns = (array) $this->option('inn');
        $country = (string) $this->option('country');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = Company::query()
            ->whereNotNull('tax_id')
            ->where('tax_id', '!=', '');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        if (! empty($inns)) {
            $query->whereIn('tax_id', $inns);
        }

        if ($country !== '') {
            $countryEnum = Country::tryFrom(strtoupper($country));
            if ($countryEnum === null) {
                $this->warn("Неизвестная страна '{$country}', фильтр по стране не применён.");
            } else {
                $query->where('country', $countryEnum);
            }
        }

        $query->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<string, string>
     */
    private function buildChanges(Company $company, array $party, string $mode): array
    {
        $data = $party['data'] ?? [];
        $address = $data['address']['unrestricted_value'] ?? $data['address']['value'] ?? '';

        $candidate = array_filter([
            'name' => $data['name']['short_with_opf'] ?? $party['value'] ?? null,
            'legal_name' => $data['name']['full_with_opf'] ?? $party['unrestricted_value'] ?? null,
            'registration_number' => $data['ogrn'] ?? null,
            'tax_code' => $data['kpp'] ?? null,
            'okpo_code' => $data['okpo'] ?? null,
            'legal_address' => $address ?: null,
            'actual_address' => $address ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        $changes = [];
        foreach ($candidate as $field => $newValue) {
            $current = (string) ($company->getAttribute($field) ?? '');

            $shouldWrite = match ($mode) {
                self::MODE_FILL_EMPTY => $current === '',
                self::MODE_NORMALIZE_NAMES => in_array($field, ['name', 'legal_name'], true) || $current === '',
                self::MODE_FULL => true,
                default => false,
            };

            if ($shouldWrite && $current !== (string) $newValue) {
                $changes[$field] = (string) $newValue;
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, string>  $changes
     */
    private function renderDiff(Company $company, array $changes): void
    {
        $rows = [];
        foreach ($changes as $field => $newValue) {
            $rows[] = [
                $field,
                (string) ($company->getAttribute($field) ?? ''),
                $newValue,
            ];
        }

        $this->newLine();
        $this->line("Компания #{$company->getKey()} (ИНН {$company->getAttribute('tax_id')}):");
        $this->table(['Поле', 'Сейчас', 'Будет'], $rows);
    }
}
