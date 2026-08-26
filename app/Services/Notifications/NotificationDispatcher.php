<?php

namespace App\Services\Notifications;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Services\Crm\CrmEmailService;
use App\Services\Crm\Mail\LegacySenders;
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
    public function __construct(
        private readonly NotificationRouter $router,
        private readonly CrmEmailService $emails,
        private readonly LegacySenders $legacy,
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

        if ($addresses === []) {
            $letter->forceFill([
                'to' => [],
                'status' => EmailStatus::UNMATCHED->value,
                'skip_reason' => 'Партнёр не подписан на это уведомление',
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
        if (! config('mail_stream.autosend')) {
            return 'Автоотправка выключена администратором';
        }

        // Пока по этому поводу пишет зашитый листенер, уведомление молчит:
        // порядок переключения можно перепутать, а письмо клиенту не отозвать.
        $legacy = $this->legacy->conflictFor($letter);

        if ($legacy !== null) {
            return 'По этому поводу пока пишет старый механизм ('.$legacy.')';
        }

        return null;
    }
}
