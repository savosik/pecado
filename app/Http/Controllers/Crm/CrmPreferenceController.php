<?php

namespace App\Http\Controllers\Crm;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Личные настройки сотрудника в CRM.
 *
 * Здесь то, что меняет содержимое всех разделов сразу и потому не может жить
 * в localStorage, как разрез «только мои»: разрез сужает уже разрешённое, а
 * настройка ниже раздвигает границу видимости (в пределах права).
 */
class CrmPreferenceController extends CrmController
{
    /**
     * «Нераспределённые»: показывать ли партнёров без персонального менеджера.
     *
     * С v16.10.0 менеджера 1С не присылает, лиды копятся без закрепления. По
     * умолчанию их не видит никто — у менеджера свой список; галочка включает
     * их во все разделы CRM: списки, счётчики, поиск, открытие карточки.
     */
    public function unassigned(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ], [
            'enabled.required' => 'Укажите, показывать ли нераспределённых.',
            'enabled.boolean' => 'Укажите, показывать ли нераспределённых.',
        ]);

        $this->crmActor($request)->forceFill([
            'crm_show_unassigned' => (bool) $validated['enabled'],
        ])->save();

        return back();
    }
}
