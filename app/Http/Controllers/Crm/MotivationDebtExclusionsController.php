<?php

namespace App\Http\Controllers\Crm;

use App\Models\Motivation\MotivationDebtExclusion;
use App\Services\Motivation\DebtExclusionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Исключения задолженности — экран руководителя (mot-37; форма B9).
 *
 * Кандидаты на расчистку с ценой в месяц, действие «исключить» с основанием,
 * действующие исключения с возвратом долга в расчёт датой.
 */
class MotivationDebtExclusionsController extends CrmController
{
    private const DEFAULT_OLDER_THAN = 90;

    public function __construct(private readonly DebtExclusionService $exclusions) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/DebtExclusions', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'in:written_off,legal,disputed,other'],
            'excluded_from' => ['required', 'date'],
            'excluded_until' => ['nullable', 'date'],
            'document_ref' => ['required', 'string', 'max:255'],
            'shipment_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [
            'reason.required' => 'Укажите основание исключения.',
            'reason.in' => 'Неизвестное основание.',
            'excluded_from.required' => 'Укажите, с какого дня долг не участвует в расчёте.',
            'document_ref.required' => 'Укажите ссылку на основание: приказ о списании, номер претензии, номер дела.',
        ]);

        try {
            $exclusion = $this->exclusions->create($data, $this->crmActor($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => sprintf('Долг выведен из расчёта с %s. Утверждённые месяцы не пересчитываются.', $exclusion->excluded_from->format('d.m.Y')),
        ] + $this->payload($request));
    }

    public function close(Request $request, MotivationDebtExclusion $exclusion): JsonResponse
    {
        $data = $request->validate(['excluded_until' => ['required', 'date']], ['excluded_until.required' => 'Укажите дату возврата долга в расчёт.']);

        try {
            $this->exclusions->close($exclusion, CarbonImmutable::parse((string) $data['excluded_until']));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Долг возвращён в базу начисления со следующего дня. История исключения сохранена.'] + $this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $olderThan = max(0, (int) ($request->query('older_than', self::DEFAULT_OLDER_THAN)));
        $managerId = $request->integer('manager') ?: null;

        return $this->exclusions->overview($olderThan, $managerId) + [
            'query' => ['older_than' => $olderThan, 'manager' => $managerId],
            'can_edit' => $this->crmActor($request)->can('crm-motivation.edit'),
        ];
    }
}
