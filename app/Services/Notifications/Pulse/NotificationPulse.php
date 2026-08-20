<?php

namespace App\Services\Notifications\Pulse;

use App\Models\Company;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use Illuminate\Support\Facades\Log;

/**
 * Пульт уведомлений — единственная публичная точка входа доменного кода.
 *
 * Доменный код говорит «произошло вот это, у такого-то партнёра, с такими
 * числами» и на этом заканчивает. Кому уйдёт письмо и уйдёт ли вообще —
 * дело правил, а не того места, где событие случилось.
 */
class NotificationPulse
{
    public function __construct(
        private readonly NotificationEventRegistry $registry,
        private readonly SieveRunner $sieve,
        private readonly PulseDispatcher $dispatcher,
    ) {}

    /**
     * Принять сигнал и разослать по правилам.
     *
     * @return array{signal_uuid: string|null, matched: int, queued: int, skipped: int, trace: array<int, array<string, mixed>>}
     */
    public function signal(PulseSignal $signal, bool $dryRun = false): array
    {
        $empty = ['signal_uuid' => null, 'matched' => 0, 'queued' => 0, 'skipped' => 0, 'trace' => []];

        if (! $this->registry->exists($signal->eventKey)) {
            Log::warning('Пульт уведомлений: неизвестное событие', ['event' => $signal->eventKey]);

            return $empty;
        }

        // Выключенный домен не порождает сигналов вовсе — это гейт для
        // пофазного включения без релиза.
        if (! PulseMode::accepts($signal->eventKey) && ! $dryRun) {
            return $empty;
        }

        $signal = $this->enrich($signal->withUuid());
        $tags = $this->registry->get($signal->eventKey)->tags($signal->data);

        $result = $this->sieve->run($signal, $tags);

        $mode = $dryRun ? 'dry_run' : PulseMode::mode();

        $outcome = $this->dispatcher->dispatch($signal, $result['recipients'], PulseMode::mode(), $dryRun);

        $this->dispatcher->recordSignal(
            $signal,
            $tags,
            count($result['matched']),
            $outcome['queued'],
            $mode,
            $dryRun,
        );

        return [
            'signal_uuid' => $signal->uuid,
            'matched' => count($result['matched']),
            'queued' => $outcome['queued'],
            'skipped' => $outcome['skipped'],
            'trace' => $result['trace'],
        ];
    }

    /**
     * Прогон без отправки: «кто получил бы».
     *
     * @return array{signal_uuid: string|null, matched: int, queued: int, skipped: int, trace: array<int, array<string, mixed>>}
     */
    public function preview(PulseSignal $signal): array
    {
        return $this->signal($signal, dryRun: true);
    }

    /**
     * Дополнить контекст общими полями, которые нужны условиям и меткам.
     *
     * Считаются здесь, а не в месте диспатча: иначе каждый вызывающий обязан
     * был бы помнить про ИНН и менеджера, и рано или поздно забыл бы.
     */
    private function enrich(PulseSignal $signal): PulseSignal
    {
        $extra = [
            'event_domain' => explode('.', $signal->eventKey)[0],
            'weekday' => (int) $signal->occurredAtOrNow()->isoWeekday(),
            'hour' => (int) $signal->occurredAtOrNow()->hour,
        ];

        if ($signal->clientUserId !== null) {
            $user = User::query()
                ->with('crmProfile:id,user_id,lifecycle_status')
                ->find($signal->clientUserId);

            if ($user !== null) {
                $extra['client_user_id'] = $user->id;
                $extra['client_name'] = (string) $user->display_name;
                $extra['manager_id'] = $user->personal_manager_id;
                $extra['client_status'] = $user->crmProfile?->lifecycle_status;
            }
        }

        if ($signal->companyId !== null) {
            $company = Company::query()->withoutGlobalScopes()->find($signal->companyId);

            if ($company !== null) {
                $extra['company_id'] = $company->id;
                $extra['company_name'] = (string) ($company->name ?: $company->legal_name);
                $extra['company_tax_id'] = $company->tax_id;
            }
        }

        return $signal->withData($extra);
    }
}
