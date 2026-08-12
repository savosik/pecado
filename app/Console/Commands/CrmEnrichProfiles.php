<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Crm\ClientEnrichmentService;
use App\Services\Crm\ClientProfileService;
use App\Support\Crm\CityExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Дозаполнение анкет партнёров по их же документам: город, периодичность
 * закупки, интересы.
 *
 * Команда только добавляет недостающее и никогда не переписывает заполненное.
 * Причина не в осторожности ради осторожности: город приезжает из 1С сообщением
 * `partner.updated`, и наша правка всё равно уступит следующей выгрузке, а
 * периодичность и интересы менеджер вносит руками — молча заменить его вывод
 * своей арифметикой значит отучить его от поля. Расхождения и подозрительные
 * значения (город «в/ч 36045») команда показывает отдельным списком, чтобы
 * менеджер разобрал их сам.
 *
 * По умолчанию — сухой прогон. Запись только с `--apply`.
 */
class CrmEnrichProfiles extends Command
{
    protected $signature = 'crm:enrich-profiles
        {--apply : Записать предложенные значения (по умолчанию — только отчёт)}
        {--fields=* : Ограничить поля: city, cycle, interests (по умолчанию все)}
        {--client=* : Только указанные партнёры (id)}
        {--limit=0 : Обработать не больше N партнёров}
        {--report= : Выгрузить построчный отчёт в CSV-файл}';

    protected $description = 'Дозаполнить анкеты партнёров: город, периодичность заказов, интересы';

    /** @var list<string> */
    private const ALL_FIELDS = ['city', 'cycle', 'interests'];

    public function handle(ClientEnrichmentService $enrichment, ClientProfileService $profiles): int
    {
        $apply = (bool) $this->option('apply');
        $fields = $this->fields();

        if ($fields === []) {
            $this->error('Неизвестное поле в --fields. Допустимо: '.implode(', ', self::ALL_FIELDS));

            return self::FAILURE;
        }

        $ids = array_filter(array_map('intval', (array) $this->option('client')));
        $limit = (int) $this->option('limit');

        $query = $enrichment->targets()->with('crmProfile');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $clients = $query->get();

        $stat = array_fill_keys(['всего партнёров', 'город записан', 'периодичность записана', 'интересы записаны'], 0);
        $skipped = ['город уже заполнен' => 0, 'город не найден' => 0];
        $conflicts = [];
        $suspicious = [];
        $rows = [];

        $bar = $this->output->createProgressBar($clients->count());
        $bar->start();

        foreach ($clients as $client) {
            $stat['всего партнёров']++;
            $suggestion = $enrichment->suggest($client);

            $applied = [];

            // --- Город ---
            $currentCity = trim((string) $client->city);
            $city = $suggestion['city'];

            if (in_array('city', $fields, true)) {
                if ($city === null) {
                    $skipped['город не найден']++;
                } elseif ($currentCity !== '') {
                    $skipped['город уже заполнен']++;

                    if (mb_strtolower($currentCity) !== mb_strtolower($city['value'])) {
                        $conflicts[] = [$client, $currentCity, $city];
                    }

                    if (! CityExtractor::looksLikeCity($currentCity)) {
                        $suspicious[] = [$client, $currentCity, $city];
                    }
                } else {
                    if ($apply) {
                        // Тихо: заполнение справочника — не событие в жизни партнёра,
                        // и подписчики на изменение пользователя (рассылки, индексация)
                        // не должны срабатывать на 400 записей подряд.
                        $client->city = $city['value'];
                        $client->saveQuietly();
                    }

                    $stat['город записан']++;
                    $applied['city'] = $city['value'].' ('.$city['source'].')';
                }
            }

            // --- Периодичность и интересы: оба живут в анкете ---
            $profile = $profiles->forClient($client);
            $profileUpdates = [];

            if (in_array('cycle', $fields, true)
                && $profile->order_cycle_days === null
                && $suggestion['order_cycle_days'] !== null
            ) {
                $profileUpdates['order_cycle_days'] = $suggestion['order_cycle_days'];
                $stat['периодичность записана']++;
                $applied['order_cycle_days'] = $suggestion['order_cycle_days'].' дн.';
            }

            $interests = $suggestion['interests'];
            $writeInterests = in_array('interests', $fields, true)
                && $interests !== []
                && $profiles->interests($client) === [];

            if ($writeInterests) {
                $stat['интересы записаны']++;
                $applied['interests'] = implode(', ', $interests);
            }

            if ($apply && ($profileUpdates !== [] || $writeInterests)) {
                DB::transaction(function () use ($client, $profile, $profileUpdates, $writeInterests, $interests): void {
                    if ($profileUpdates !== []) {
                        $profile->fill($profileUpdates);
                        $profile->client()->associate($client);
                        $profile->save();
                    }

                    if ($writeInterests) {
                        $client->syncTagsWithType($interests, User::INTEREST_TAG_TYPE);
                    }
                });
            }

            if ($applied !== []) {
                $rows[] = [
                    'id' => $client->id,
                    'партнёр' => $client->display_name,
                    'город' => $applied['city'] ?? '',
                    'периодичность' => $applied['order_cycle_days'] ?? '',
                    'интересы' => $applied['interests'] ?? '',
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->renderSummary($stat, $skipped, $apply);
        $this->renderConflicts($conflicts, $suspicious);

        if ($report = $this->option('report')) {
            $this->writeReport((string) $report, $rows, $conflicts, $suspicious);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function fields(): array
    {
        $fields = array_filter((array) $this->option('fields'));

        if ($fields === []) {
            return self::ALL_FIELDS;
        }

        return array_diff($fields, self::ALL_FIELDS) === [] ? array_values($fields) : [];
    }

    /**
     * @param  array<string, int>  $stat
     * @param  array<string, int>  $skipped
     */
    private function renderSummary(array $stat, array $skipped, bool $apply): void
    {
        $this->info($apply ? 'Записано в анкеты:' : 'Сухой прогон — ничего не записано. Было бы заполнено:');

        $this->table(['Показатель', 'Партнёров'], collect($stat)->map(
            fn (int $value, string $label): array => [$label, $value]
        )->values()->all());

        $this->line('Пропущено: '.collect($skipped)->map(
            fn (int $value, string $label): string => "$label — $value"
        )->implode('; '));

        if (! $apply) {
            $this->newLine();
            $this->comment('Запись: php artisan crm:enrich-profiles --apply');
        }
    }

    /**
     * @param  list<array{0: User, 1: string, 2: array{value: string, source: string, candidates: array<string, string>}}>  $conflicts
     * @param  list<array{0: User, 1: string, 2: array{value: string, source: string, candidates: array<string, string>}}>  $suspicious
     */
    private function renderConflicts(array $conflicts, array $suspicious): void
    {
        if ($suspicious !== []) {
            $this->newLine();
            $this->warn('Город в базе не похож на город — стоит проверить руками ('.count($suspicious).'):');

            $this->table(
                ['ID', 'Партнёр', 'В базе', 'Найдено в документах'],
                collect($suspicious)->take(30)->map(fn (array $row): array => [
                    $row[0]->id,
                    mb_substr($row[0]->display_name, 0, 40),
                    $row[1],
                    $row[2]['value'].' ('.$row[2]['source'].')',
                ])->all(),
            );
        }

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('Документы называют другой город ('.count($conflicts).', показаны первые 30):');

            $this->table(
                ['ID', 'Партнёр', 'В базе', 'Кандидаты'],
                collect($conflicts)->take(30)->map(fn (array $row): array => [
                    $row[0]->id,
                    mb_substr($row[0]->display_name, 0, 40),
                    $row[1],
                    collect($row[2]['candidates'])->map(fn (string $city, string $source): string => "$city — $source")->implode('; '),
                ])->all(),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{0: User, 1: string, 2: array{value: string, source: string, candidates: array<string, string>}}>  $conflicts
     * @param  list<array{0: User, 1: string, 2: array{value: string, source: string, candidates: array<string, string>}}>  $suspicious
     */
    private function writeReport(string $path, array $rows, array $conflicts, array $suspicious): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Не удалось открыть файл отчёта: $path");

            return;
        }

        // BOM — иначе Excel открывает кириллицу в CSV нечитаемой.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Раздел', 'ID', 'Партнёр', 'Город', 'Периодичность', 'Интересы']);

        foreach ($rows as $row) {
            fputcsv($handle, ['заполнено', $row['id'], $row['партнёр'], $row['город'], $row['периодичность'], $row['интересы']]);
        }

        foreach ($suspicious as $row) {
            fputcsv($handle, ['город в базе не похож на город', $row[0]->id, $row[0]->display_name, $row[1].' → '.$row[2]['value'], '', '']);
        }

        foreach ($conflicts as $row) {
            $candidates = collect($row[2]['candidates'])->map(fn (string $city, string $source): string => "$city — $source")->implode('; ');
            fputcsv($handle, ['расхождение с документами', $row[0]->id, $row[0]->display_name, $row[1].' vs '.$candidates, '', '']);
        }

        fclose($handle);

        $this->newLine();
        $this->info("Отчёт: $path");
    }
}
