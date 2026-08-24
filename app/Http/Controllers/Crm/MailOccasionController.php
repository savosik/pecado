<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Services\Crm\Mail\LegacySenders;
use App\Services\Crm\Mail\MailOccasions;
use App\Services\Crm\Mail\MailTagBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Реестр поводов: что система вообще умеет присылать.
 *
 * Подписать партнёра можно только на то, что видно. До этого экрана список
 * событий существовал лишь в конфиге, и «на что бывает подписка» приходилось
 * выяснять по письмам, которые уже пришли.
 *
 * Отдельного пункта меню нет — это вкладка внутри «Писем», рядом с правилами.
 */
class MailOccasionController extends CrmController
{
    public function __construct(
        private readonly MailOccasions $occasions,
        private readonly MailTagBuilder $tags,
        private readonly LegacySenders $legacy,
    ) {}

    public function index(Request $request): Response
    {
        $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        $collected = $this->countBy(null);
        $unmatched = $this->countBy(EmailStatus::UNMATCHED);

        $occasions = collect($this->occasions->catalog())
            ->map(function (array $occasion) use ($collected, $unmatched): array {
                $key = $occasion['key'];

                return $occasion + [
                    'domain_enabled' => $this->occasions->domainEnabled($occasion['domain']),
                    'collected' => (int) ($collected[$key] ?? 0),
                    // Главное число экрана: столько писем по этому поводу
                    // не получил никто. Ноль при непустом «собрано» означает,
                    // что повод разобран правилами полностью.
                    'unmatched' => (int) ($unmatched[$key] ?? 0),
                    'tags' => $this->tags->occasionTags($key),
                    // Пока по поводу пишет зашитый листенер, подписка на него
                    // даст клиенту два письма. Автоотправка это остановит,
                    // но знать, какой флаг гасить, менеджер должен заранее.
                    'legacy_senders' => $this->legacy->activeFor($key),
                ];
            })
            ->sortByDesc(fn (array $occasion): int => $occasion['unmatched'])
            ->values()
            ->all();

        return Inertia::render('Crm/Pages/Emails/Occasions', [
            'occasions' => $occasions,
            'streamEnabled' => (bool) config('mail_stream.enabled'),
            'days' => 30,
        ]);
    }

    /**
     * Сколько писем по каждому поводу за месяц.
     *
     * @return array<string, int>
     */
    private function countBy(?EmailStatus $status): array
    {
        return CrmEmail::query()
            ->where('origin', 'system')
            ->where('created_at', '>=', now()->subDays(30))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->selectRaw('origin_event, count(*) as total')
            ->groupBy('origin_event')
            ->pluck('total', 'origin_event')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
