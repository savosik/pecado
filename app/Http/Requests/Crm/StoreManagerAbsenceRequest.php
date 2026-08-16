<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\ManagerAbsenceType;
use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreManagerAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm-absences.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'personal_manager_id' => ['required', 'integer', Rule::exists('personal_managers', 'id')],
            'substitute_manager_id' => ['nullable', 'integer', 'different:personal_manager_id', Rule::exists('personal_managers', 'id')],
            'type' => ['required', Rule::enum(ManagerAbsenceType::class)],
            // Прошлые даты разрешены: прогул фиксируется задним числом.
            'starts_on' => ['required', 'date', 'after:-1 year', 'before:+1 year'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before:+1 year'],
            'comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'personal_manager_id.required' => 'Выберите менеджера.',
            'personal_manager_id.exists' => 'Такого менеджера нет.',
            'substitute_manager_id.different' => 'Менеджер не может замещать сам себя.',
            'substitute_manager_id.exists' => 'Такого замещающего нет.',
            'type.required' => 'Выберите тип отсутствия.',
            'starts_on.required' => 'Укажите первый день отсутствия.',
            'ends_on.required' => 'Укажите последний день отсутствия.',
            'ends_on.after_or_equal' => 'Последний день не может быть раньше первого.',
            'starts_on.before' => 'Отсутствие можно запланировать не дальше, чем на год вперёд.',
            'ends_on.before' => 'Отсутствие можно запланировать не дальше, чем на год вперёд.',
            'comment.max' => 'Комментарий не длиннее 255 символов.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Carbon::parse($this->input('starts_on'));
            $to = Carbon::parse($this->input('ends_on'));
            $managerId = (int) $this->input('personal_manager_id');
            $substituteId = $this->filled('substitute_manager_id') ? (int) $this->input('substitute_manager_id') : null;

            // Пересечение периодов одного менеджера запрещено независимо от типа:
            // две записи «кто вместо него» на один день не разрешить.
            $clash = ManagerAbsence::query()
                ->where('personal_manager_id', $managerId)
                ->overlapping($from, $to)
                ->with('manager:id,name')
                ->first();

            if ($clash) {
                $validator->errors()->add('starts_on', sprintf(
                    'У %s уже есть «%s» с %s по %s — периоды не должны пересекаться. Завершите или удалите существующую запись.',
                    $clash->manager->name,
                    $clash->type->label(),
                    $clash->starts_on->format('d.m.Y'),
                    $clash->ends_on->format('d.m.Y'),
                ));
            }

            // Запрет цепочек: уходящий менеджер не должен быть чьим-то замещающим
            // в этот период — иначе резолверу пришлось бы ходить по цепочке.
            // Проверяется и для записей без замещающего: прогульщик, замещающий
            // коллегу, — тоже дыра в покрытии клиентов.
            $chained = ManagerAbsence::query()
                ->where('substitute_manager_id', $managerId)
                ->overlapping($from, $to)
                ->with('manager:id,name')
                ->first();

            if ($chained) {
                $validator->errors()->add('personal_manager_id', sprintf(
                    'Этот менеджер назначен замещающим у %s до %s — сначала смените замещающего в той записи.',
                    $chained->manager->name,
                    $chained->ends_on->format('d.m.Y'),
                ));
            }

            if ($substituteId === null) {
                return;
            }

            $substitute = PersonalManager::find($substituteId);

            if ($substitute && ! $substitute->is_active) {
                $validator->errors()->add('substitute_manager_id', sprintf(
                    'Карточка «%s» скрыта из работы — выберите действующего менеджера.',
                    $substitute->name,
                ));
            }

            // Замещающий сам отсутствует в пересекающийся период.
            $substituteAway = ManagerAbsence::query()
                ->where('personal_manager_id', $substituteId)
                ->overlapping($from, $to)
                ->with('manager:id,name')
                ->first();

            if ($substituteAway) {
                $validator->errors()->add('substitute_manager_id', sprintf(
                    '%s сам(а) отсутствует с %s по %s — выберите другого замещающего.',
                    $substituteAway->manager->name,
                    $substituteAway->starts_on->format('d.m.Y'),
                    $substituteAway->ends_on->format('d.m.Y'),
                ));
            }
        });
    }
}
