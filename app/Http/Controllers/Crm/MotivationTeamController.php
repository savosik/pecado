<?php

namespace App\Http\Controllers\Crm;

use App\Models\Motivation\MotivationObjection;
use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Services\Motivation\CorrectionService;
use App\Services\Motivation\TeamSummaryService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\Support\MonthLabel;
use App\Services\SimpleXlsxExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Сводка отдела и ведомость к утверждению (mot-34; формы B2, B3).
 *
 * Обе страницы и их JSON собираются одним сервисом из тех же снимков,
 * что видят работники. Действия над расчётом — тот же жизненный цикл,
 * что у действующей зарплаты: черновик → утверждён → выплачен, переоткрытие
 * новой версией. Автоматической заморозки нет: руководитель утверждает руками.
 */
class MotivationTeamController extends CrmController
{
    public function __construct(
        private readonly TeamSummaryService $team,
        private readonly PayrollCalculationService $calculations,
        private readonly CorrectionService $corrections,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Team', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function approval(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Approval', $this->payload($request, withObjections: true));
    }

    public function approvalData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, withObjections: true));
    }

    /**
     * XLSX для бухгалтерии: строка на работника, колонки — строки Положения.
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $payload = $this->payload($request);

        $rows = array_map(fn (array $row): array => [
            $row['manager']['name'],
            $row['calculation']['status_label'].($row['calculation']['version'] > 1 ? ' v'.$row['calculation']['version'] : ''),
            $row['plan'],
            $row['shipped'],
            $row['percent'] === null ? null : round($row['percent'] * 100, 1),
            $row['fixed'],
            $row['p1'],
            $row['p2'],
            $row['p3'],
            -$row['k1'],
            $row['variable'],
            $row['correction'],
            $row['guarantee'],
            $row['total'],
            $row['overdue'],
            $row['active_partners'].' из '.$row['partners_total'],
            $row['calculation']['approved_at'] ? substr((string) $row['calculation']['approved_at'], 0, 10) : null,
            $row['calculation']['comment'],
        ], $payload['rows']);

        return $exporter->stream(
            sprintf('motivaciya-%s.xlsx', $payload['month']),
            ['Работник', 'Статус', 'План', 'Отгружено базе', 'Выполнение, %', 'Оклад и надбавки', 'П1', 'П2', 'П3', 'К1', 'Переменная часть', 'Корректировка', 'Доплата до гарантии', 'Итого', 'Просрочка', 'Активных партнёров', 'Утверждено', 'Комментарий'],
            $rows,
            'Мотивация '.$payload['month_label'],
        );
    }

    public function recalculate(PayrollCalculation $calculation): JsonResponse
    {
        if ($calculation->isFrozen()) {
            return response()->json(['message' => 'Расчёт утверждён — сначала переоткройте его.'], 422);
        }

        $this->calculations->recalculateDraft((int) $calculation->personal_manager_id, $calculation->period_month, 'manual');

        return $this->afterAction($calculation);
    }

    public function approve(Request $request, PayrollCalculation $calculation): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:255']], ['comment.max' => 'Комментарий не длиннее 255 символов.']);

        if (! $calculation->isDraft()) {
            return response()->json(['message' => 'Утвердить можно только черновик.'], 422);
        }

        // Перед заморозкой — свежие входы: руководитель утверждает то, что видит.
        $fresh = $this->calculations->recalculateDraft((int) $calculation->personal_manager_id, $calculation->period_month, 'approve') ?? $calculation;
        $this->calculations->approve($fresh, $this->crmActor($request), $data['comment'] ?? null);

        return $this->afterAction($calculation, 'Расчёт утверждён и заморожен.');
    }

    public function reopen(Request $request, PayrollCalculation $calculation): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'min:5', 'max:255']], [
            'comment.required' => 'Переоткрытие требует основания — оно попадёт в историю версий.',
            'comment.min' => 'Основание слишком короткое.',
        ]);

        if ($calculation->isDraft()) {
            return response()->json(['message' => 'Переоткрыть можно только утверждённый расчёт.'], 422);
        }

        $this->calculations->reopen($calculation, $this->crmActor($request), (string) $data['comment']);

        return $this->afterAction($calculation, 'Создана новая версия черновика; прежняя осталась в истории.');
    }

    public function markPaid(Request $request, PayrollCalculation $calculation): JsonResponse
    {
        if ($calculation->status !== PayrollCalculation::STATUS_APPROVED) {
            return response()->json(['message' => 'Отметить выплаченным можно только утверждённый расчёт.'], 422);
        }

        $this->calculations->markPaid($calculation, $this->crmActor($request));

        return $this->afterAction($calculation, 'Отмечено выплаченным.');
    }

    public function storeCorrection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:personal_managers,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'manager_id.required' => 'Не указан работник.',
            'amount.required' => 'Укажите сумму корректировки со знаком.',
            'amount.not_in' => 'Сумма корректировки не может быть нулевой.',
            'reason.required' => 'Корректировка требует основания.',
            'reason.min' => 'Основание слишком короткое.',
        ]);

        try {
            $this->corrections->add(
                (int) $data['manager_id'],
                CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01'),
                (float) $data['amount'],
                (string) $data['reason'],
                $this->crmActor($request),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Корректировка внесена, расчёт пересчитывается.'] + $this->payload($request, withObjections: true));
    }

    public function destroyCorrection(Request $request, PayrollManualAdjustment $adjustment): JsonResponse
    {
        try {
            $this->corrections->remove($adjustment);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $this->payload($request->merge(['month' => CarbonImmutable::instance($adjustment->period_month)->format('Y-m')]), withObjections: true));
    }

    public function respondObjection(Request $request, MotivationObjection $objection): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'response' => ['nullable', 'string', 'max:2000'],
        ], [
            'decision.required' => 'Укажите решение: принять или отклонить.',
            'decision.in' => 'Решение: принять или отклонить.',
        ]);

        try {
            $this->corrections->respond($objection, (string) $data['decision'], $data['response'] ?? null, $this->crmActor($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $month = $objection->calculation?->period_month;

        return response()->json([
            'ok' => true,
            'message' => $data['decision'] === 'accepted' ? 'Возражение принято: месяц переоткрыт новой версией.' : 'Возражение отклонено с обоснованием.',
        ] + $this->payload($request->merge(['month' => $month === null ? null : CarbonImmutable::instance($month)->format('Y-m')]), withObjections: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, bool $withObjections = false): array
    {
        $month = $this->month($request);
        $summary = $this->team->build($month);

        $payload = $summary + [
            'month_label' => MonthLabel::ru($month),
            'months' => $this->months(),
            'can_edit' => $this->crmActor($request)->can('crm-motivation.edit'),
        ];

        if ($withObjections) {
            $managerIds = array_map(fn (array $r): int => (int) $r['manager']['id'], $summary['rows']);

            // Возражения — по всем версиям расчётов месяца, а не только по последней:
            // принятое возражение переоткрывает месяц новой версией, и оно обязано
            // остаться на экране рядом с ней, а не исчезнуть вместе с прежней.
            $payload['objections'] = MotivationObjection::query()
                ->with(['manager:id,name', 'author:id,name', 'calculation:id,version,status'])
                ->whereHas('calculation', fn ($q) => $q
                    ->whereIn('personal_manager_id', $managerIds)
                    ->whereDate('period_month', $month->toDateString()))
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->get()
                ->map(fn (MotivationObjection $o): array => [
                    'id' => (int) $o->getKey(),
                    'manager' => $o->manager?->name,
                    'version' => $o->calculation?->version,
                    'reason' => $o->reason,
                    'status' => $o->status,
                    'status_label' => $o->statusLabel(),
                    'response' => $o->response,
                    'created_at' => $o->created_at?->toIso8601String(),
                    'responded_at' => $o->responded_at?->toIso8601String(),
                ])
                ->all();

            $payload['corrections'] = PayrollManualAdjustment::query()
                ->with('author:id,name')
                ->whereIn('personal_manager_id', $managerIds)
                ->whereDate('period_month', $month->toDateString())
                ->where('component_key', PayrollManualAdjustment::COMPONENT_MANUAL_CORRECTION)
                ->orderByDesc('id')
                ->get()
                ->map(fn (PayrollManualAdjustment $a): array => [
                    'id' => (int) $a->getKey(),
                    'manager_id' => (int) $a->personal_manager_id,
                    'amount' => (float) $a->amount,
                    'reason' => $a->comment ?? $a->label,
                    'author' => $a->author?->name,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])
                ->all();

            $payload['adjustment_limit'] = (float) config('motivation.default_parameters.adjustment_limit', 0.15);
        }

        return $payload;
    }

    private function afterAction(PayrollCalculation $calculation, ?string $message = null): JsonResponse
    {
        $request = request()->merge(['month' => CarbonImmutable::instance($calculation->period_month)->format('Y-m')]);

        return response()->json(array_filter(['ok' => true, 'message' => $message]) + $this->payload($request, withObjections: true));
    }

    private function month(Request $request): CarbonImmutable
    {
        $raw = (string) $request->input('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');

            if ($month !== null && $month->lte(CarbonImmutable::now()->startOfMonth())) {
                return $month->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function months(): array
    {
        $rows = [];
        $cursor = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['value' => $cursor->format('Y-m'), 'label' => MonthLabel::ru($cursor)];
            $cursor = $cursor->subMonth();
        }

        return $rows;
    }
}
