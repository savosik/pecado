<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends CrmController
{
    public function index(Request $request): Response
    {
        // Скрытые карточки на самой «Команде» показываем — иначе снять пометку
        // было бы негде. Из остальной CRM они уже исчезли.
        $managers = PersonalManager::query()
            ->with(['user:id,name,email', 'media'])
            ->withCount('users')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (PersonalManager $manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'phone' => $manager->phone,
                'email' => $manager->email,
                'photo_url' => $manager->getFirstMediaUrl('photo'),
                'clients_count' => $manager->users_count,
                'has_erp_uuid' => filled($manager->erp_uuid),
                'is_active' => $manager->is_active,
                'account' => $manager->user ? [
                    'name' => $manager->user->name,
                    'email' => $manager->user->email,
                ] : null,
            ]);

        return Inertia::render('Crm/Pages/Team/Index', [
            'managers' => $managers,
            'canEdit' => $this->crmActor($request)->can('crm-team.edit'),
        ]);
    }

    /**
     * Скрыть нерабочую карточку менеджера или вернуть её в работу.
     *
     * Удаления здесь нет и не будет: карточку с `erp_uuid` следующий обмен
     * создаст заново, а закреплённые за ней партнёры потеряют менеджера.
     * Флаг убирает мусор из выборов, не трогая ни историю, ни привязки.
     */
    public function setActive(Request $request, PersonalManager $manager): RedirectResponse
    {
        $request->validate(
            ['is_active' => ['required', 'boolean']],
            ['is_active.required' => 'Не указано, включить карточку или скрыть.'],
        );

        $isActive = $request->boolean('is_active');

        $manager->update(['is_active' => $isActive]);

        $message = $isActive
            ? "Карточка «{$manager->name}» снова в работе"
            : "Карточка «{$manager->name}» скрыта из списков CRM";

        // Партнёры остаются закреплёнными за скрытой карточкой — предупреждаем,
        // иначе их выручка молча выпала бы из разреза по менеджерам.
        if (! $isActive && $manager->users()->count() > 0) {
            $message .= '. За ней остались партнёры — переназначьте их в 1С, иначе в отчётах они останутся без менеджера.';
        }

        return back()->with('success', $message);
    }
}
