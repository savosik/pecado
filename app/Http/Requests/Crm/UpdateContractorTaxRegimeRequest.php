<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\VatPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractorTaxRegimeRequest extends FormRequest
{
    /**
     * Право то же, что у анкеты партнёра: это такое же знание менеджера о клиенте,
     * отдельный ресурс в матрице ролей ничего бы не добавил.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('crm-profile.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // «Ещё не решил» бывает только про будущее: режим сейчас у юрлица есть всегда.
            'current_regime' => ['required', Rule::enum(TaxRegime::class)->except([TaxRegime::UNDECIDED])],
            'planned_regime' => ['required', Rule::enum(TaxRegime::class)],
            'vat_preference' => ['nullable', Rule::enum(VatPreference::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_regime.required' => 'Укажите, на какой системе налогообложения юрлицо работает сейчас.',
            'current_regime.enum' => 'Выберите текущий режим из списка. «Партнёр ещё не решил» подходит только для плана.',
            'planned_regime.required' => 'Укажите план на следующий год — или «Партнёр ещё не решил».',
            'planned_regime.enum' => 'Выберите план на следующий год из списка.',
            'note.max' => 'Комментарий длиннее 500 символов.',
        ];
    }
}
