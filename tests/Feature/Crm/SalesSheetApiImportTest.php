<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Services\Crm\CrmApiClient;
use App\Services\Crm\SalesSheetApiImporter;
use App\Support\Crm\SalesSheet;
use App\Support\Crm\SalesSheetRow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Импорт таблицы продаж в боевую CRM через агентское API.
 *
 * Путь существует потому, что доступа к прод-серверу по SSH нет: единственный
 * способ внести планы — те же операции, которыми пользуется ИИ-агент менеджера.
 * Тест держит форму запросов: ошибиться здесь означает записать планы не тому
 * клиенту на боевом сервере, где отката нет.
 */
class SalesSheetApiImportTest extends TestCase
{
    private function sheet(array $rows, array $departmentPlans = []): SalesSheet
    {
        return new SalesSheet(rows: $rows, departmentPlans: $departmentPlans);
    }

    private function importer(): SalesSheetApiImporter
    {
        return new SalesSheetApiImporter(new CrmApiClient('https://pecado.test', 'secret-token'));
    }

    /**
     * @param  list<array<string, mixed>>  $clients
     */
    private function fakeCrm(array $clients): void
    {
        Http::fake([
            'https://pecado.test/api/crm/clients?*' => Http::response([
                'data' => $clients,
                'meta' => ['page' => 1, 'per_page' => 100, 'total' => count($clients), 'last_page' => 1],
            ]),
            'https://pecado.test/api/crm/clients/*/profile' => Http::response([
                'lifecycle_status' => 'active',
                'lifecycle_changed_at' => null,
            ]),
            'https://pecado.test/api/crm/clients/*/lifecycle' => Http::response(['ok' => true]),
            'https://pecado.test/api/crm/plans' => Http::response(['saved' => 1, 'removed' => 0, 'skipped' => 0]),
        ]);
    }

    #[Test]
    #[TestDox('Планы клиента уходят в plan.set помесячно, с id из боевой базы')]
    public function it_posts_client_plans_by_month(): void
    {
        $this->fakeCrm([
            ['id' => 512, 'name' => 'Гевея, г. Москва', 'manager' => ['id' => 3, 'name' => 'Сухов Иван']],
        ]);

        $report = $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Гевея, г. Москва', plans: ['2026-02' => 1_728_440.0, '2026-03' => 1_200_000.0]),
        ]));

        $this->assertSame(1, $report->clientsMatched);
        $this->assertSame(2, $report->plansSaved);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://pecado.test/api/crm/plans' || $request['month'] !== '2026-02') {
                return false;
            }

            $client = collect($request['rows'])->firstWhere('target_type', 'client');

            return $client['target_id'] === 512 && $client['amount'] === 1_728_440.0;
        });
    }

    #[Test]
    #[TestDox('План менеджера считается из клиентов и строки «Новые клиенты»')]
    public function it_posts_manager_plan_including_new_clients(): void
    {
        $this->fakeCrm([
            ['id' => 512, 'name' => 'Гевея, г. Москва', 'manager' => ['id' => 3, 'name' => 'Сухов Иван']],
        ]);

        $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Гевея, г. Москва', plans: ['2026-02' => 1_000_000.0]),
            new SalesSheetRow(
                line: 4,
                name: 'Новые клиенты Иван',
                kind: SalesSheetRow::KIND_NEW_CLIENTS,
                manager: 'Сухов Иван',
                plans: ['2026-02' => 2_000_000.0],
            ),
        ]));

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://pecado.test/api/crm/plans') {
                return false;
            }

            $manager = collect($request['rows'])->firstWhere('target_type', 'manager');

            return $manager !== null && $manager['target_id'] === 3 && $manager['amount'] === 3_000_000.0;
        });
    }

    #[Test]
    #[TestDox('План отдела уходит целью department без target_id')]
    public function it_posts_department_plan(): void
    {
        $this->fakeCrm([]);

        $this->importer()->import($this->sheet([], ['2026-02' => 19_260_445.0]));

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://pecado.test/api/crm/plans') {
                return false;
            }

            $department = collect($request['rows'])->firstWhere('target_type', 'department');

            return $department !== null && $department['amount'] === 19_260_445.0;
        });
    }

    #[Test]
    #[TestDox('Паспорт и статус уходят отдельными вызовами по клиенту')]
    public function it_patches_profile_and_changes_status(): void
    {
        $this->fakeCrm([
            ['id' => 77, 'name' => 'Авента ООО г.Новосибирск', 'manager' => null],
        ]);

        $this->importer()->import($this->sheet([
            new SalesSheetRow(
                line: 3,
                name: 'Авента ООО г.Новосибирск',
                status: ClientLifecycleStatus::SLEEPING,
                businessType: BusinessType::OFFLINE,
                hasOfflinePoints: true,
                pointsCount: 11,
            ),
        ]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://pecado.test/api/crm/clients/77/profile'
            && $request['business_type'] === 'offline'
            && $request['has_offline_points'] === true
            && $request['points_count'] === 11);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://pecado.test/api/crm/clients/77/lifecycle'
            && $request['lifecycle_status'] === 'sleeping');
    }

    #[Test]
    #[TestDox('Заполненное в боевой карточке поле импорт не затирает')]
    public function it_does_not_overwrite_filled_fields(): void
    {
        Http::fake([
            'https://pecado.test/api/crm/clients?*' => Http::response([
                'data' => [['id' => 77, 'name' => 'Авента ООО г.Новосибирск', 'manager' => null]],
                'meta' => ['last_page' => 1],
            ]),
            'https://pecado.test/api/crm/clients/*/profile' => Http::response([
                'points_count' => 42,
                'business_type' => null,
                'lifecycle_status' => 'active',
                'lifecycle_changed_at' => null,
            ]),
            'https://pecado.test/api/crm/plans' => Http::response(['saved' => 0, 'skipped' => 0]),
        ]);

        $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Авента ООО г.Новосибирск', businessType: BusinessType::OFFLINE, pointsCount: 11),
        ]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request['business_type'] === 'offline'
            && ! array_key_exists('points_count', (array) $request->data()));
    }

    #[Test]
    #[TestDox('Статус, изменённый в CRM вручную, остаётся как был')]
    public function it_respects_manually_changed_status(): void
    {
        Http::fake([
            'https://pecado.test/api/crm/clients?*' => Http::response([
                'data' => [['id' => 77, 'name' => 'Авента ООО г.Новосибирск', 'manager' => null]],
                'meta' => ['last_page' => 1],
            ]),
            'https://pecado.test/api/crm/clients/*/profile' => Http::response([
                'lifecycle_status' => 'in_work',
                'lifecycle_changed_at' => '2026-08-01T10:00:00+03:00',
            ]),
            'https://pecado.test/api/crm/plans' => Http::response(['saved' => 0, 'skipped' => 0]),
        ]);

        $report = $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Авента ООО г.Новосибирск', status: ClientLifecycleStatus::CHURNED),
        ]));

        $this->assertSame(0, $report->statusesChanged);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/lifecycle'));
    }

    #[Test]
    #[TestDox('Пробный прогон не пишет в боевую CRM ничего')]
    public function it_writes_nothing_on_dry_run(): void
    {
        $this->fakeCrm([
            ['id' => 512, 'name' => 'Гевея, г. Москва', 'manager' => ['id' => 3, 'name' => 'Сухов Иван']],
        ]);

        $report = $this->importer()->import(
            $this->sheet([new SalesSheetRow(line: 3, name: 'Гевея, г. Москва', plans: ['2026-02' => 1_000_000.0])]),
            dryRun: true,
        );

        $this->assertSame(1, $report->plansSaved);

        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PATCH'], true));
    }

    #[Test]
    #[TestDox('Ненайденный в боевой базе клиент попадает в отчёт, а не в чужую строку')]
    public function it_reports_unmatched_clients(): void
    {
        $this->fakeCrm([
            ['id' => 512, 'name' => 'Гевея, г. Москва', 'manager' => null],
        ]);

        $report = $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Неизвестное ООО, г.Тверь', plans: ['2026-02' => 500_000.0]),
        ]));

        $this->assertSame(0, $report->clientsMatched);
        $this->assertCount(1, $report->unmatched);
        $this->assertSame(500_000.0, $report->lostAmount);
    }

    #[Test]
    #[TestDox('Пропущенные сервером строки попадают в замечания, а не теряются молча')]
    public function it_warns_when_server_skips_rows(): void
    {
        Http::fake([
            'https://pecado.test/api/crm/clients?*' => Http::response([
                'data' => [['id' => 512, 'name' => 'Гевея, г. Москва', 'manager' => null]],
                'meta' => ['last_page' => 1],
            ]),
            'https://pecado.test/api/crm/clients/*/profile' => Http::response(['lifecycle_status' => 'active']),
            'https://pecado.test/api/crm/plans' => Http::response(['saved' => 0, 'removed' => 0, 'skipped' => 1]),
        ]);

        $report = $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Гевея, г. Москва', plans: ['2026-02' => 1_000_000.0]),
        ]));

        $this->assertNotEmpty(array_filter(
            $report->warnings,
            fn (string $warning): bool => str_contains($warning, 'нет права'),
        ));
    }

    #[Test]
    #[TestDox('Отказ сервера не проглатывается: причина видна в сообщении')]
    public function it_surfaces_api_errors(): void
    {
        Http::fake([
            'https://pecado.test/api/crm/clients?*' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $this->expectExceptionMessageMatches('/HTTP 401/');

        $this->importer()->import($this->sheet([
            new SalesSheetRow(line: 3, name: 'Гевея, г. Москва', plans: ['2026-02' => 1_000_000.0]),
        ]));
    }
}
