<?php

namespace App\Http\Controllers\User;

use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\VatPreference;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\TaxRegime\ClientTaxSurvey;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Опрос клиента о налогах и НДС — ответы уходят в CRM менеджеру.
 *
 * В режиме просмотра от имени клиента опрос проходит менеджер: ответ пишется
 * от его имени (источник «со слов менеджера»), а не как слова клиента.
 * Откладывать опрос за клиента менеджер не может — «Не сейчас» у него ничего
 * не сохраняет.
 */
class TaxSurveyController extends Controller
{
    public function store(Request $request, ClientTaxSurvey $survey): RedirectResponse
    {
        $client = $request->user();
        $manager = null;

        if (Impersonation::active()) {
            $manager = User::query()->find(Impersonation::managerId());
            abort_if($manager === null, 403, 'Не удалось определить менеджера, открывшего сайт от имени клиента.');
        }

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:50'],
            'answers.*.company_id' => [
                'required',
                'integer',
                Rule::exists('companies', 'id')
                    ->where('user_id', $client->getKey())
                    ->whereNull('deleted_at'),
            ],
            'answers.*.current_regime' => ['required', Rule::enum(TaxRegime::class)->except([TaxRegime::UNDECIDED])],
            'answers.*.planned_regime' => ['nullable', Rule::enum(TaxRegime::class)],
            'answers.*.vat_preference' => ['nullable', Rule::enum(VatPreference::class)],
        ], [
            'answers.required' => 'Выберите хотя бы один ответ.',
            'answers.*.company_id.exists' => 'Эта компания не найдена среди ваших.',
            'answers.*.current_regime.required' => 'Укажите, как компания работает сейчас.',
            'answers.*.current_regime.enum' => 'Выберите вариант из списка.',
            'answers.*.planned_regime.enum' => 'Выберите вариант из списка.',
            'answers.*.vat_preference.enum' => 'Выберите вариант из списка.',
        ]);

        $survey->save($client, $validated['answers'], $manager);

        return back();
    }

    public function snooze(Request $request, ClientTaxSurvey $survey): RedirectResponse
    {
        if (! Impersonation::active()) {
            $survey->snooze($request->user());
        }

        return back();
    }
}
