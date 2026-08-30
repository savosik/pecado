<?php

namespace App\Http\Controllers\Crm;

use App\Models\PayrollCalculation;
use App\Services\Payroll\PayrollCalculationPresenter;
use App\Services\Payroll\PayrollCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Жизненный цикл снимка: черновик → утверждён → выплачен; переоткрытие.
 *
 * Утверждение замораживает: поздние оплаты и отгрузки задним числом на снимок
 * не влияют. Передумал — «переоткрыть»: рядом появляется новая версия черновика,
 * старая остаётся как была.
 */
class SalaryApprovalController extends CrmController
{
    public function __construct(
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculationPresenter $presenter,
    ) {}

    public function recalculate(PayrollCalculation $calculation): JsonResponse
    {
        if ($calculation->isFrozen()) {
            return response()->json(['message' => 'Расчёт утверждён — сначала переоткройте его.'], 422);
        }

        $fresh = $this->calculations->recalculateDraft(
            (int) $calculation->personal_manager_id,
            $calculation->period_month,
            'manual',
        );

        return response()->json(['saved' => true, 'calculation' => $this->presenter->present($fresh ?? $calculation)]);
    }

    public function approve(Request $request, PayrollCalculation $calculation): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:255']], ['comment.max' => 'Комментарий не может быть длиннее 255 символов.']);

        if (! $calculation->isDraft()) {
            return response()->json(['message' => 'Утвердить можно только черновик.'], 422);
        }

        // Перед заморозкой — свежие входы: РОП утверждает то, что видит на экране,
        // а экран мог отстать от последней отгрузки на минуту опроса.
        $fresh = $this->calculations->recalculateDraft((int) $calculation->personal_manager_id, $calculation->period_month, 'approve') ?? $calculation;
        $approved = $this->calculations->approve($fresh, $this->crmActor($request), $data['comment'] ?? null);

        return response()->json(['saved' => true, 'calculation' => $this->presenter->present($approved)]);
    }

    public function reopen(Request $request, PayrollCalculation $calculation): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:255']], ['comment.max' => 'Комментарий не может быть длиннее 255 символов.']);

        if ($calculation->isDraft()) {
            return response()->json(['message' => 'Переоткрыть можно только утверждённый расчёт.'], 422);
        }

        $draft = $this->calculations->reopen($calculation, $this->crmActor($request), $data['comment'] ?? null);

        return response()->json(['saved' => true, 'calculation' => $this->presenter->present($draft)]);
    }

    public function markPaid(Request $request, PayrollCalculation $calculation): JsonResponse
    {
        if ($calculation->status !== PayrollCalculation::STATUS_APPROVED) {
            return response()->json(['message' => 'Отметить выплаченным можно только утверждённый расчёт.'], 422);
        }

        $paid = $this->calculations->markPaid($calculation, $this->crmActor($request));

        return response()->json(['saved' => true, 'calculation' => $this->presenter->present($paid)]);
    }
}
