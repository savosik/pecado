<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\ManagerAbsenceType;
use App\Http\Requests\Crm\StoreManagerAbsenceRequest;
use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Отсутствия и замещения менеджеров (эпик abs-00).
 *
 * Пока менеджер отсутствует и у записи указан замещающий, кабинеты его
 * партнёров показывают контакты замещающего, а письма о заказах уходят ему.
 * Смотреть раздел может весь отдел, управлять — руководитель.
 */
class AbsenceController extends CrmController
{
    public function index(Request $request): Response
    {
        // Завершённые старше полугода в списке не нужны — это уже история
        // для табеля, а не рабочая информация «кто кого замещает».
        $absences = ManagerAbsence::query()
            ->with(['manager:id,name', 'substitute:id,name', 'author:id,name'])
            ->whereDate('ends_on', '>=', today()->subMonths(6))
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (ManagerAbsence $absence): array => [
                'id' => $absence->id,
                'manager' => $absence->manager->name,
                'substitute' => $absence->substitute?->name,
                'type' => $absence->type->value,
                'type_label' => $absence->type->label(),
                'type_color' => $absence->type->color(),
                'starts_on' => $absence->starts_on->format('d.m.Y'),
                'ends_on' => $absence->ends_on->format('d.m.Y'),
                'comment' => $absence->comment,
                'author' => $absence->author?->name,
                'is_active' => $absence->starts_on->lte(today()) && $absence->ends_on->gte(today()),
                'is_upcoming' => $absence->starts_on->gt(today()),
            ]);

        return Inertia::render('Crm/Pages/Absences/Index', [
            'absences' => $absences,
            'managers' => PersonalManager::query()
                ->active()
                ->whereNotNull('user_id')
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (PersonalManager $manager): array => [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'has_email' => filled($manager->email),
                ]),
            'types' => ManagerAbsenceType::optionsWithColor(),
            'canEdit' => $this->crmActor($request)->can('crm-absences.edit'),
        ]);
    }

    public function store(StoreManagerAbsenceRequest $request): RedirectResponse
    {
        $absence = ManagerAbsence::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);
        $absence->load(['manager:id,name', 'substitute:id,name,email']);

        $message = sprintf(
            'Отсутствие добавлено: %s — %s по %s',
            $absence->manager->name,
            mb_strtolower($absence->type->label()),
            $absence->ends_on->format('d.m.Y'),
        );

        if ($absence->substitute) {
            $message .= ", замещает {$absence->substitute->name}";

            // Пустой email — не блок, а предупреждение: письма о заказах
            // в этот период поедут на адрес самого отсутствующего.
            if (blank($absence->substitute->email)) {
                $message .= '. У замещающего в карточке нет email — письма о заказах будут приходить на адрес отсутствующего менеджера';
            }
        }

        return back()->with('success', $message);
    }

    /**
     * Досрочное завершение: «вышел раньше» = сдвиг последнего дня на вчера.
     * Партнёры снова видят своего менеджера уже сегодня.
     */
    public function finish(Request $request, ManagerAbsence $absence): RedirectResponse
    {
        if ($absence->starts_on->gt(today())) {
            return back()->withErrors([
                'absence' => 'Отсутствие ещё не началось — его можно просто удалить.',
            ]);
        }

        if ($absence->ends_on->lt(today())) {
            return back()->withErrors([
                'absence' => 'Отсутствие уже завершилось.',
            ]);
        }

        $absence->update(['ends_on' => today()->subDay()]);

        return back()->with('success', "Отсутствие завершено: {$absence->manager->name} снова на связи у своих партнёров");
    }

    /**
     * Удаление — для ошибочно созданных записей: строка исчезает и из табеля.
     */
    public function destroy(ManagerAbsence $absence): RedirectResponse
    {
        $absence->delete();

        return back()->with('success', 'Запись удалена');
    }
}
