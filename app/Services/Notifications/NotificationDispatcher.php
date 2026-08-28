<?php

namespace App\Services\Notifications;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\PersonalManager;
use App\Services\Crm\CrmEmailService;
use App\Support\Notifications\Occasion;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Отправка уведомления по настройке партнёра.
 *
 * Заменяет связку «правило нашло письмо → менеджер нажал самолётик».
 * Уведомление настраивается один раз и дальше уходит само: это его смысл,
 * а очередь черновиков на подтверждение — смысл писем менеджера.
 */
class NotificationDispatcher
{
    /** Тип уведомления, по которому менеджеру уже пишет служебный листенер. */
    private const STAFF_COVERED_OCCASION = 'orders.created';

    public function __construct(
        private readonly NotificationRouter $router,
        private readonly CrmEmailService $emails,
    ) {}

    /**
     * Отправить собранное уведомление.
     *
     * Адресаты определяются в момент отправки, а не сборки: за окно склейки
     * настройка могла измениться, и уйти должно то, что настроено сейчас.
     *
     * @return list<string>
     */
    public function send(CrmEmail $letter): array
    {
        $occasion = $this->occasionOf($letter);
        $addresses = $this->router->addressesFor($occasion);
        $skipReason = 'Партнёр не подписан на это уведомление';

        if ($addresses !== [] && $occasion->key === self::STAFF_COVERED_OCCASION) {
            $addresses = $this->withoutManager($letter, $addresses);
            $skipReason = 'Менеджеру о новом заказе уходит служебное письмо, дубль по настройке партнёра не нужен';
        }

        if ($addresses === []) {
            $letter->forceFill([
                'to' => [],
                'status' => EmailStatus::UNMATCHED->value,
                'skip_reason' => $skipReason,
            ])->save();

            return [];
        }

        $letter->forceFill(['to' => $addresses])->save();

        $refusal = $this->refusal($letter);

        if ($refusal !== null) {
            $letter->forceFill(['status' => EmailStatus::DRAFT->value, 'skip_reason' => $refusal])->save();

            return [];
        }

        try {
            $this->emails->send($letter->refresh());
        } catch (RuntimeException $exception) {
            $letter->forceFill(['skip_reason' => $exception->getMessage()])->save();

            Log::warning('Уведомление не ушло', [
                'letter' => $letter->getKey(),
                'occasion' => $occasion->key,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $letter->forceFill(['skip_reason' => null])->save();

        return $addresses;
    }

    /**
     * Восстановить повод из письма.
     *
     * Письмо хранит всё, что нужно маршрутизатору: тип, партнёра, контрагента
     * и числа повода. Отдельного хранилища для этого не заводим.
     */
    private function occasionOf(CrmEmail $letter): Occasion
    {
        $data = (array) $letter->origin_data;

        return new Occasion(
            key: (string) $letter->origin_event,
            clientUserId: $letter->client_user_id === null ? null : (int) $letter->client_user_id,
            // Только контрагент, названный поводом: `company_id` может быть
            // юрлицом партнёра по умолчанию, и сужать по нему адресацию нельзя.
            companyId: isset($data['occasion_company_id']) ? (int) $data['occasion_company_id'] : null,
            data: $data,
        );
    }

    /**
     * Почему уведомление не уходит прямо сейчас — или null, если препятствий нет.
     */
    private function refusal(CrmEmail $letter): ?string
    {
        // Новая маршрутизация приезжает на прод раньше, чем кто-то посмотрел
        // на умолчания глазами. Пока рубильник выключен, уведомление
        // адресуется, но не уходит: видно, кому что ушло бы, и ничего не ушло.
        if (! config('mail_stream.notifications_live')) {
            return 'Уведомления по настройкам партнёра ещё не включены (MAIL_NOTIFICATIONS_LIVE)';
        }

        if (! config('mail_stream.autosend')) {
            return 'Автоотправка выключена администратором';
        }

        return null;
    }

    /**
     * Убрать из адресатов персонального менеджера клиента.
     *
     * О новом заказе менеджеру пишет служебный листенер (`NotifyManagersAboutNewOrder`,
     * настройка `staff.order_created`). Если партнёру дополнительно поставили
     * адресата «менеджер», письмо задвоилось бы — то же событие, два письма.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function withoutManager(CrmEmail $letter, array $addresses): array
    {
        $managerId = $letter->client?->personal_manager_id;
        $managerEmail = $managerId === null
            ? null
            : PersonalManager::query()->whereKey($managerId)->value('email');

        if (blank($managerEmail)) {
            return $addresses;
        }

        return array_values(array_filter(
            $addresses,
            fn (string $address): bool => mb_strtolower($address) !== mb_strtolower((string) $managerEmail),
        ));
    }
}
