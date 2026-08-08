<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\PlanTarget;
use App\Models\CrmClientProfile;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\SalesSheetImporter;
use App\Services\Crm\SalesSheetReader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Перенос управленческой таблицы продаж в CRM.
 *
 * Таблица живёт в Google Sheets и правится людьми: колонки переезжают, месяцы
 * подписаны то «июнь», то «июл», клиент заводится дважды. Тест держит именно эти
 * свойства — что разбор опирается на подписи, а не на буквы колонок, и что
 * сомнительная строка попадает в отчёт, а не приписывается случайному клиенту.
 */
class SalesSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private PersonalManager $sukhov;

    private PersonalManager $kurochkina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->author = User::factory()->create(['email' => 'head@pecado.ru']);
        $this->author->assignRole('sales-head');

        $this->sukhov = PersonalManager::factory()->create(['name' => 'Сухов Иван']);
        $this->kurochkina = PersonalManager::factory()->create(['name' => 'Курочкина Алёна Валерьевна']);
    }

    /**
     * Файл, повторяющий форму настоящей таблицы: две строки шапки, подписи месяцев
     * вперемешку с «фактом», итог отдела в строке подписей.
     *
     * @param  list<array<int, mixed>>  $rows  строки клиентов: колонка => значение
     * @param  array<int, mixed>  $totals  итог отдела: колонка => значение
     */
    private function makeSheet(array $rows, array $totals = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ОПТ действующие');

        // Первая строка шапки: признаки и месяцы. Между планами месяца намеренно
        // стоят «факт» и «% выполнения» — как в настоящей выгрузке.
        $sheet->fromArray([
            null, "Активный\nСпящий\nЛид", "Офлайн\nОнлайн\nСеллер",
            'Есть офлайн-точки', 'Есть интернет-магазин', 'Работает с маркетплейсами',
            'Количество Магазинов', 'Сайт интернет-магазина', null,
            'Февраль 2025', '2026-02-01', 'фев.26 корректировка', 'Факт Февраль 2026', '% выполнения',
            'Март 2025', '2026-03-01', 'мар.26 корректировка', 'Факт март 2026',
            'Итого корректировка  план 2026',
        ], null, 'A1');

        // Вторая строка шапки: часть подписей живёт здесь, и здесь же итог отдела.
        $sheet->fromArray([
            'Москва', 'Статус клиента', 'Тик Клиента', null, null, null, null, null, 'Менеджер',
        ], null, 'A2');

        foreach ($totals as $column => $value) {
            $sheet->setCellValue([$column, 2], $value);
        }

        $line = 3;

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column, $line], $value);
            }

            $line++;
        }

        $sheet->setCellValue([1, $line], 'Итого');
        $sheet->setCellValue([12, $line], 999_999_999);

        $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * Колонки файла из makeSheet(): 12 — план февраля, 17 — план марта.
     *
     * @param  array<int, mixed>  $overrides
     * @return array<int, mixed>
     */
    private function clientRow(string $name, array $overrides = []): array
    {
        return [1 => $name] + $overrides;
    }

    private function import(string $path, bool $dryRun = false, bool $overwrite = false): \App\Support\Crm\SalesSheetImportReport
    {
        $sheet = app(SalesSheetReader::class)->read($path, 'ОПТ действующие');

        return app(SalesSheetImporter::class)->import($sheet, $this->author, $dryRun, $overwrite);
    }

    #[Test]
    #[TestDox('План клиента берётся из колонки «корректировка», а не из плана и не из факта')]
    public function it_imports_client_plans_from_correction_columns(): void
    {
        $client = User::factory()->create([
            'erp_name' => 'Гевея, г. Москва',
            'personal_manager_id' => $this->sukhov->id,
        ]);

        $path = $this->makeSheet([
            $this->clientRow('Гевея, г. Москва', [
                11 => 2_650_000,  // план — не он
                12 => 1_728_440,  // корректировка февраля
                13 => 2_054_263,  // факт — тоже не он
                17 => 1_200_000,  // корректировка марта
            ]),
        ]);

        $report = $this->import($path);

        $this->assertSame(1, $report->clientsMatched);
        $this->assertSame(2, $report->plansSaved);

        $february = CrmSalesPlan::query()->forClient((int) $client->id)->forPeriod(now()->setDate(2026, 2, 1))->first();
        $march = CrmSalesPlan::query()->forClient((int) $client->id)->forPeriod(now()->setDate(2026, 3, 1))->first();

        $this->assertNotNull($february);
        $this->assertSame(1_728_440.0, $february->amountValue());
        $this->assertNotNull($march);
        $this->assertSame(1_200_000.0, $march->amountValue());
    }

    #[Test]
    #[TestDox('Ноль в корректировке планом не считается')]
    public function it_treats_zero_as_absence_of_plan(): void
    {
        User::factory()->create(['erp_name' => 'Арсенал ООО г. Ульяновск']);

        $path = $this->makeSheet([
            $this->clientRow('Арсенал ООО г. Ульяновск', [12 => 0, 17 => null]),
        ]);

        $report = $this->import($path);

        $this->assertSame(1, $report->clientsMatched);
        $this->assertSame(0, $report->plansSaved);
        $this->assertSame(0, CrmSalesPlan::query()->where('target_type', PlanTarget::CLIENT->value)->count());
    }

    #[Test]
    #[TestDox('Паспорт и статус клиента заполняются из таблицы')]
    public function it_fills_passport_and_lifecycle_status(): void
    {
        $client = User::factory()->create(['erp_name' => 'Авента ООО г.Новосибирск']);

        $path = $this->makeSheet([
            $this->clientRow('Авента ООО г.Новосибирск', [
                2 => 'Активный', 3 => 'Офлайн', 4 => 'да', 5 => 'да', 6 => 'да', 7 => 11,
            ]),
        ]);

        $this->import($path);

        $profile = CrmClientProfile::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(BusinessType::OFFLINE, $profile->business_type);
        $this->assertTrue($profile->has_offline_points);
        $this->assertTrue($profile->has_online_store);
        $this->assertTrue($profile->works_with_marketplaces);
        $this->assertSame(11, $profile->points_count);
        $this->assertSame(ClientLifecycleStatus::ACTIVE, $profile->lifecycle_status);
    }

    #[Test]
    #[TestDox('Пустая ячейка признака означает «не выясняли», а не «нет»')]
    public function it_leaves_unfilled_flags_null(): void
    {
        $client = User::factory()->create(['erp_name' => 'Ким Александр Александрович ИП, г.Москва']);

        $path = $this->makeSheet([
            $this->clientRow('Ким Александр Александрович ИП, г.Москва', [
                2 => 'Активный', 3 => 'Селлер', 6 => 'да',
            ]),
        ]);

        $this->import($path);

        $profile = CrmClientProfile::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(BusinessType::SELLER, $profile->business_type);
        $this->assertTrue($profile->works_with_marketplaces);
        $this->assertNull($profile->has_offline_points);
        $this->assertNull($profile->has_online_store);
    }

    #[Test]
    #[TestDox('Новые статусы «Закрывается» и «Непреодолимо» переносятся как есть')]
    public function it_imports_new_lifecycle_statuses(): void
    {
        $closing = User::factory()->create(['erp_name' => 'Самохвалова Валерия Олеговна ИП']);
        $hopeless = User::factory()->create(['erp_name' => 'ЭКСЕЛЕНТ ООО']);

        $path = $this->makeSheet([
            $this->clientRow('Самохвалова Валерия Олеговна ИП', [2 => 'Закрывается']),
            $this->clientRow('ЭКСЕЛЕНТ ООО', [2 => 'Непреодолимо']),
        ]);

        $this->import($path);

        $this->assertSame(
            ClientLifecycleStatus::CLOSING,
            CrmClientProfile::query()->where('user_id', $closing->id)->firstOrFail()->lifecycle_status,
        );
        $this->assertSame(
            ClientLifecycleStatus::HOPELESS,
            CrmClientProfile::query()->where('user_id', $hopeless->id)->firstOrFail()->lifecycle_status,
        );
    }

    #[Test]
    #[TestDox('Клиент, заведённый в таблице дважды, получает сумму обеих строк')]
    public function it_sums_duplicated_rows(): void
    {
        $client = User::factory()->create(['erp_name' => 'Винокурова Наталья Валентиновна ИП, г.Москва']);

        $path = $this->makeSheet([
            $this->clientRow('Винокурова Наталья Валентиновна ИП, г.Москва', [12 => 300_000]),
            $this->clientRow('Винокурова Наталья Валентиновна ИП, г.Москва.', [12 => 70_000]),
        ]);

        $report = $this->import($path);

        $plan = CrmSalesPlan::query()->forClient((int) $client->id)->forPeriod(now()->setDate(2026, 2, 1))->firstOrFail();

        $this->assertSame(370_000.0, $plan->amountValue());
        $this->assertNotEmpty($report->warnings);
    }

    #[Test]
    #[TestDox('Ненайденный клиент попадает в отчёт вместе с потерянной суммой')]
    public function it_reports_unmatched_clients(): void
    {
        $path = $this->makeSheet([
            $this->clientRow('Неизвестное ООО, г.Тверь', [12 => 500_000]),
        ]);

        $report = $this->import($path);

        $this->assertSame(0, $report->clientsMatched);
        $this->assertCount(1, $report->unmatched);
        $this->assertSame('Неизвестное ООО, г.Тверь', $report->unmatched[0]['name']);
        $this->assertSame(500_000.0, $report->lostAmount);
    }

    #[Test]
    #[TestDox('Одноимённые клиенты не получают план наугад')]
    public function it_skips_ambiguous_clients(): void
    {
        User::factory()->create(['erp_name' => 'Иванов Сергей Васильевич г.Омск']);
        User::factory()->create(['erp_name' => 'Иванов Сергей Васильевич г.Омск']);

        $path = $this->makeSheet([
            $this->clientRow('Иванов Сергей Васильевич г.Омск', [12 => 400_000]),
        ]);

        $report = $this->import($path);

        $this->assertCount(1, $report->ambiguous);
        $this->assertSame(0, $report->plansSaved);
        $this->assertSame(400_000.0, $report->lostAmount);
    }

    #[Test]
    #[TestDox('План менеджера складывается из планов его клиентов и строки «Новые клиенты»')]
    public function it_builds_manager_plan_from_clients_and_new_clients_bucket(): void
    {
        User::factory()->create([
            'erp_name' => 'Гевея, г. Москва',
            'personal_manager_id' => $this->sukhov->id,
        ]);

        $path = $this->makeSheet([
            $this->clientRow('Гевея, г. Москва', [12 => 1_000_000]),
            $this->clientRow('Новые клиенты Иван', [9 => 'Сухов Иван', 12 => 2_000_000]),
        ]);

        $this->import($path);

        $plan = CrmSalesPlan::query()
            ->forManager((int) $this->sukhov->id)
            ->forPeriod(now()->setDate(2026, 2, 1))
            ->firstOrFail();

        $this->assertSame(3_000_000.0, $plan->amountValue());
    }

    #[Test]
    #[TestDox('Менеджер в таблице записан с городом — он всё равно опознаётся')]
    public function it_matches_manager_written_with_city_prefix(): void
    {
        $path = $this->makeSheet([
            $this->clientRow('Новые клиенты Елена', [9 => 'Москва: Курочкина Алёна Валерьевна', 12 => 700_000]),
        ]);

        $report = $this->import($path);

        $this->assertEmpty($report->unmatched);

        $plan = CrmSalesPlan::query()
            ->forManager((int) $this->kurochkina->id)
            ->forPeriod(now()->setDate(2026, 2, 1))
            ->firstOrFail();

        $this->assertSame(700_000.0, $plan->amountValue());
    }

    #[Test]
    #[TestDox('План отдела берётся из итоговой строки, а не из строки «Итого» внизу')]
    public function it_imports_department_plan_from_header_totals(): void
    {
        $path = $this->makeSheet(
            [$this->clientRow('Гевея, г. Москва', [12 => 1_000_000])],
            [12 => 19_260_445, 17 => 16_000_005],
        );

        $this->import($path);

        $february = CrmSalesPlan::query()->department()->forPeriod(now()->setDate(2026, 2, 1))->firstOrFail();

        $this->assertSame(19_260_445.0, $february->amountValue());
        $this->assertSame(2, CrmSalesPlan::query()->department()->count());
    }

    #[Test]
    #[TestDox('Пробный прогон ничего не записывает, но считает так же')]
    public function it_changes_nothing_on_dry_run(): void
    {
        User::factory()->create(['erp_name' => 'Гевея, г. Москва']);

        $path = $this->makeSheet([
            $this->clientRow('Гевея, г. Москва', [2 => 'Спящий', 12 => 1_000_000]),
        ]);

        $report = $this->import($path, dryRun: true);

        $this->assertSame(1, $report->clientsMatched);
        $this->assertSame(1, $report->plansSaved);
        $this->assertSame(0, CrmSalesPlan::query()->count());
        $this->assertSame(0, CrmClientProfile::query()->count());
    }

    #[Test]
    #[TestDox('Повторный запуск обновляет план, а не плодит второй на тот же месяц')]
    public function it_updates_plan_on_repeated_run(): void
    {
        $client = User::factory()->create(['erp_name' => 'Гевея, г. Москва']);

        $this->import($this->makeSheet([$this->clientRow('Гевея, г. Москва', [12 => 1_000_000])]));
        $this->import($this->makeSheet([$this->clientRow('Гевея, г. Москва', [12 => 1_500_000])]));

        $plans = CrmSalesPlan::query()->forClient((int) $client->id)->forPeriod(now()->setDate(2026, 2, 1))->get();

        $this->assertCount(1, $plans);
        $this->assertSame(1_500_000.0, $plans->first()->amountValue());
    }

    #[Test]
    #[TestDox('Заполненное менеджером поле паспорта импорт не затирает без --overwrite')]
    public function it_keeps_manually_filled_passport_fields(): void
    {
        $client = User::factory()->create(['erp_name' => 'Гевея, г. Москва']);
        CrmClientProfile::factory()->create([
            'user_id' => $client->id,
            'points_count' => 42,
            'business_type' => BusinessType::CHAIN,
        ]);

        $path = $this->makeSheet([
            $this->clientRow('Гевея, г. Москва', [3 => 'Офлайн', 4 => 'да', 7 => 60]),
        ]);

        $this->import($path);

        $profile = CrmClientProfile::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(42, $profile->points_count);
        $this->assertSame(BusinessType::CHAIN, $profile->business_type);
        // Пустое поле дополняется — в этом смысл повторного прогона.
        $this->assertTrue($profile->has_offline_points);
    }

    #[Test]
    #[TestDox('С --overwrite таблица перекрывает заполненные поля')]
    public function it_overwrites_passport_when_asked(): void
    {
        $client = User::factory()->create(['erp_name' => 'Гевея, г. Москва']);
        CrmClientProfile::factory()->create([
            'user_id' => $client->id,
            'points_count' => 42,
            'business_type' => BusinessType::CHAIN,
        ]);

        $path = $this->makeSheet([
            $this->clientRow('Гевея, г. Москва', [3 => 'Офлайн', 7 => 60]),
        ]);

        $this->import($path, overwrite: true);

        $profile = CrmClientProfile::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(60, $profile->points_count);
        $this->assertSame(BusinessType::OFFLINE, $profile->business_type);
    }

    #[Test]
    #[TestDox('Статус, выставленный менеджером вручную, импорт не переписывает')]
    public function it_respects_manually_changed_lifecycle_status(): void
    {
        $client = User::factory()->create(['erp_name' => 'Гевея, г. Москва']);
        CrmClientProfile::factory()->create([
            'user_id' => $client->id,
            'lifecycle_status' => ClientLifecycleStatus::IN_WORK,
            'lifecycle_changed_at' => now(),
            'lifecycle_changed_by' => $this->author->id,
        ]);

        $path = $this->makeSheet([
            $this->clientRow('Гевея, г. Москва', [2 => 'Закрылся']),
        ]);

        $this->import($path);

        $this->assertSame(
            ClientLifecycleStatus::IN_WORK,
            CrmClientProfile::query()->where('user_id', $client->id)->firstOrFail()->lifecycle_status,
        );
    }

    #[Test]
    #[TestDox('Служебные строки «Итого» и подписи месяцев клиентами не считаются')]
    public function it_ignores_total_rows(): void
    {
        $path = $this->makeSheet([
            $this->clientRow('Итого по Москве', [12 => 5_000_000]),
        ]);

        $report = $this->import($path);

        $this->assertSame(0, $report->clientsMatched);
        $this->assertEmpty($report->unmatched);
    }
}
