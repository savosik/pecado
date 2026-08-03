<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\PlanTarget;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Массовое сохранение сетки планов.
 *
 * Сетка отправляется строками «цель → сумма»: так один запрос закрывает и правку
 * одной ячейки, и заполнение месяца целиком, и правку нескольких месяцев сразу.
 *
 * Пустая сумма — это снять план, а не поставить ноль: «плана нет» и «план ноль»
 * в отчёте о выполнении означают разное.
 */
class StoreSalesPlansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'string', 'max:10'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.target_type' => ['required', Rule::enum(PlanTarget::class)],
            'rows.*.target_id' => ['nullable', 'integer', 'min:1'],
            // Верхняя граница — вместимость decimal(15,2): большее число молча
            // обрезалось бы базой, а план — деньги.
            'rows.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'rows.*.comment' => ['nullable', 'string', 'max:255'],
            'rows.*.month' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * Цель обязательна всем, кроме отдела: отдел в системе один.
     *
     * Проверяем здесь, а не правилом `required_unless` с подстановкой индекса —
     * так сообщение об ошибке указывает на конкретную строку сетки.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $this->input('rows', []);

                foreach ($rows as $index => $row) {
                    $type = PlanTarget::tryFrom((string) ($row['target_type'] ?? ''));

                    if ($type === null || ! $type->needsTarget()) {
                        continue;
                    }

                    if (empty($row['target_id'])) {
                        $validator->errors()->add(
                            "rows.{$index}.target_id",
                            'Не указано, кому ставится план.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'Нечего сохранять: не изменена ни одна ячейка.',
            'rows.max' => 'За один раз можно сохранить не больше 500 строк.',
            'rows.*.target_type.required' => 'Не указан тип плана.',
            'rows.*.target_type.enum' => 'Такого типа плана нет.',
            'rows.*.amount.numeric' => 'Сумма плана должна быть числом.',
            'rows.*.amount.min' => 'Сумма плана не может быть отрицательной.',
            'rows.*.amount.max' => 'Слишком большая сумма плана.',
            'rows.*.comment.max' => 'Пояснение не может быть длиннее 255 символов.',
        ];
    }
}
