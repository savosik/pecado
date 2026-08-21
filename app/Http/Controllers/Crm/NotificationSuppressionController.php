<?php

namespace App\Http\Controllers\Crm;

use App\Models\ClientContact;
use App\Models\NotificationSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Стоп-лист адресов.
 *
 * Здесь видно, почему на адрес не уходят письма: отписался сам, отвергнут
 * почтовым сервером, пожаловался на спам или внесён сотрудником.
 *
 * Снятие записи — только под правом на все правила: возврат адреса, который
 * система признала битым, должен быть осознанным решением, а не рефлексом
 * «клиент жалуется, что не получает письма».
 */
class NotificationSuppressionController extends CrmController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->crmActor($request);

        $filters = $request->validate([
            'email' => ['nullable', 'string', 'max:191'],
            'reason' => ['nullable', Rule::in([
                NotificationSuppression::REASON_UNSUBSCRIBED,
                NotificationSuppression::REASON_BOUNCE,
                NotificationSuppression::REASON_COMPLAINT,
                NotificationSuppression::REASON_MANUAL,
            ])],
        ]);

        $rows = NotificationSuppression::query()
            ->with('contact:id,full_name,user_id,company_id')
            ->when(filled($filters['email'] ?? null), fn ($q) => $q->where('email', 'like', '%'.$filters['email'].'%'))
            ->when(filled($filters['reason'] ?? null), fn ($q) => $q->where('reason', $filters['reason']))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Crm/Pages/Notifications/Suppressions', [
            'rows' => $rows->through(fn (NotificationSuppression $s) => [
                'id' => $s->id,
                'email' => $s->email,
                'scope' => $s->scope,
                'scope_label' => $this->scopeLabel($s->scope),
                'reason' => $s->reason,
                'reason_label' => $this->reasonLabel($s->reason),
                'note' => $s->note,
                'contact_name' => $s->contact?->full_name,
                'created_at' => $s->created_at?->format('d.m.Y H:i'),
                'expires_at' => $s->expires_at?->format('d.m.Y'),
            ]),
            'filters' => $filters,
            'canRemove' => $request->user()->can('crm-notifications-all.edit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->crmActor($request);

        abort_unless($actor->can('crm-notifications-all.edit'), 403,
            'Стоп-лист ведёт руководитель отдела');

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'scope' => ['required', Rule::in([NotificationSuppression::SCOPE_ALL, NotificationSuppression::SCOPE_MARKETING])],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['email' => 'адрес', 'scope' => 'область', 'note' => 'пояснение']);

        $email = mb_strtolower(trim($data['email']));

        NotificationSuppression::updateOrCreate(
            ['email' => $email, 'scope' => $data['scope']],
            [
                'reason' => NotificationSuppression::REASON_MANUAL,
                'note' => $data['note'] ?? null,
                'contact_id' => ClientContact::query()->where('email', $email)->value('id'),
            ],
        );

        return back()->with('success', 'Адрес добавлен в стоп-лист');
    }

    public function destroy(Request $request, int $suppression): RedirectResponse
    {
        $actor = $this->crmActor($request);

        abort_unless($actor->can('crm-notifications-all.edit'), 403,
            'Снимать записи стоп-листа может руководитель отдела');

        $record = NotificationSuppression::findOrFail($suppression);
        $email = $record->email;
        $record->delete();

        // Контакт мог быть помечен отписавшимся — снимаем и это, иначе
        // письма всё равно не пойдут, а причина будет неочевидна.
        ClientContact::query()
            ->where('email', $email)
            ->whereNotNull('unsubscribed_at')
            ->update(['unsubscribed_at' => null]);

        return back()->with('success', 'Адрес снят со стоп-листа: '.$email);
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            NotificationSuppression::REASON_UNSUBSCRIBED => 'Отписался по ссылке',
            NotificationSuppression::REASON_BOUNCE => 'Отвергнут почтовым сервером',
            NotificationSuppression::REASON_COMPLAINT => 'Жалоба на спам',
            NotificationSuppression::REASON_MANUAL => 'Внесён сотрудником',
            default => $reason,
        };
    }

    private function scopeLabel(string $scope): string
    {
        return match ($scope) {
            NotificationSuppression::SCOPE_ALL => 'Все уведомления',
            NotificationSuppression::SCOPE_MARKETING => 'Только рассылки',
            default => $scope,
        };
    }
}
