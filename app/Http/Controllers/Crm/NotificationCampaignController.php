<?php

namespace App\Http\Controllers\Crm;

use App\Enums\ClientContactRole;
use App\Models\CrmEmailTemplate;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\NotificationDelivery;
use App\Services\Notifications\Pulse\CampaignSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Рассылки и кампании.
 *
 * Отличие от остального пульта: письмо инициирует человек, и оно рекламное.
 * Поэтому аудитория собирается заранее и показывается с разбивкой «кому уйдёт,
 * кого отсеяли и почему» — до отправки, а не после.
 *
 * Запуск — только под правом на все правила: рассылка по базе не должна быть
 * в руках одного менеджера.
 */
class NotificationCampaignController extends CrmController
{
    public function index(Request $request): Response
    {
        $this->crmActor($request);

        $campaigns = NotificationCampaign::query()
            ->with('author:id,name')
            ->latest('id')
            ->paginate(30);

        return Inertia::render('Crm/Pages/Notifications/Campaigns', [
            'campaigns' => $campaigns->through(fn (NotificationCampaign $c) => $this->payload($c)),
            'templates' => CrmEmailTemplate::query()
                ->where('is_active', true)
                ->get(['id', 'name', 'subject', 'body_html']),
            'roles' => ClientContactRole::options(),
            'canSend' => $request->user()->can('crm-notifications-all.edit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $data = $this->validated($request);

        NotificationCampaign::create($data + [
            'status' => NotificationCampaign::STATUS_DRAFT,
            'created_by_user_id' => $actor->id,
        ]);

        return back()->with('success', 'Черновик рассылки создан. Соберите аудиторию, чтобы увидеть, кому она уйдёт.');
    }

    public function update(Request $request, int $campaign): RedirectResponse
    {
        $this->crmActor($request);

        $model = NotificationCampaign::findOrFail($campaign);

        abort_unless($model->isEditable(), 422, 'Отправленную рассылку править нельзя');

        $model->update($this->validated($request));

        return back()->with('success', 'Рассылка сохранена');
    }

    /**
     * Собрать аудиторию и показать разбивку — до отправки.
     */
    public function buildAudience(Request $request, int $campaign): RedirectResponse
    {
        $this->crmActor($request);

        $model = NotificationCampaign::findOrFail($campaign);

        abort_unless($model->isEditable(), 422, 'Аудитория пересобирается только у черновика');

        $result = app(CampaignSender::class)->buildAudience($model);

        $message = "Получателей: {$result['eligible']}.";

        if ($result['skipped'] !== []) {
            $parts = [];

            foreach ($result['skipped'] as $reason => $count) {
                $parts[] = NotificationDelivery::skipReasonLabel($reason).': '.$count;
            }

            $message .= ' Отсеяно — '.implode(', ', $parts).'.';
        }

        return back()->with('success', $message);
    }

    /**
     * Отправить порцию. Порциями, чтобы рассылка не задержала письма о заказах.
     */
    public function send(Request $request, int $campaign): RedirectResponse
    {
        $actor = $this->crmActor($request);

        abort_unless($actor->can('crm-notifications-all.edit'), 403,
            'Запуск рассылки — за руководителем отдела');

        $model = NotificationCampaign::findOrFail($campaign);

        abort_if(
            $model->status === NotificationCampaign::STATUS_SENT,
            422,
            'Рассылка уже отправлена',
        );

        abort_if(
            $model->recipients()->count() === 0,
            422,
            'Сначала соберите аудиторию — иначе отправлять некому',
        );

        $result = app(CampaignSender::class)->sendBatch($model);

        $message = "Отправлено писем: {$result['sent']}.";
        $message .= $result['remaining'] > 0
            ? " Осталось: {$result['remaining']} — нажмите «Отправить» ещё раз."
            : ' Рассылка завершена.';

        return back()->with('success', $message);
    }

    public function cancel(Request $request, int $campaign): RedirectResponse
    {
        $actor = $this->crmActor($request);

        abort_unless($actor->can('crm-notifications-all.edit'), 403);

        NotificationCampaign::findOrFail($campaign)
            ->update(['status' => NotificationCampaign::STATUS_CANCELLED]);

        return back()->with('success', 'Рассылка отменена');
    }

    /**
     * Разбивка аудитории для карточки рассылки.
     */
    public function audience(Request $request, int $campaign): JsonResponse
    {
        $this->crmActor($request);

        $model = NotificationCampaign::findOrFail($campaign);

        $rows = $model->recipients()
            ->with('contact:id,full_name,role')
            ->orderBy('status')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (NotificationCampaignRecipient $r) => [
                'email' => $r->email,
                'contact_name' => $r->contact?->full_name,
                'status' => $r->status,
                'skip_reason_label' => $r->skip_reason
                    ? NotificationDelivery::skipReasonLabel($r->skip_reason)
                    : null,
            ])->values(),
            'total' => $model->recipients()->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:512'],
            'body_html' => ['required', 'string'],
            'crm_email_template_id' => ['nullable', 'integer', 'exists:crm_email_templates,id'],
            'segment' => ['nullable', 'array'],
            'segment.roles' => ['nullable', 'array'],
            'segment.roles.*' => [Rule::in(ClientContactRole::values())],
            'segment.include_accounts' => ['boolean'],
            'segment.client_status' => ['nullable', 'string', 'max:30'],
        ], [], [
            'name' => 'название',
            'subject' => 'тема письма',
            'body_html' => 'текст письма',
            'segment' => 'аудитория',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(NotificationCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'subject' => $campaign->subject,
            'body_html' => $campaign->body_html,
            'segment' => $campaign->segment ?? [],
            'status' => $campaign->status,
            'status_label' => NotificationCampaign::statusLabel($campaign->status),
            'is_editable' => $campaign->isEditable(),
            'recipients_total' => $campaign->recipients_total,
            'recipients_sent' => $campaign->recipients_sent,
            'recipients_skipped' => $campaign->recipients_skipped,
            'author' => $campaign->author?->name,
            'created_at' => $campaign->created_at?->format('d.m.Y H:i'),
            'finished_at' => $campaign->finished_at?->format('d.m.Y H:i'),
        ];
    }
}
