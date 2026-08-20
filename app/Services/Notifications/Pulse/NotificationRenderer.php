<?php

namespace App\Services\Notifications\Pulse;

use App\Models\CrmEmailTemplate;
use App\Models\NotificationRule;
use App\Notifications\Pulse\Support\PulseSignal;

/**
 * Подготовка письма: шаблон, тема, ссылка отписки.
 *
 * Плейсхолдеры темы раскрываются обычной заменой строк — тем же способом,
 * что в шаблонах писем менеджера. Blade или любой другой шаблонизатор над
 * текстом из базы означал бы выполнение кода из базы.
 */
class NotificationRenderer
{
    public function __construct(private readonly NotificationEventRegistry $registry) {}

    /**
     * Тема письма: своя из правила, иначе тема события.
     */
    public function subject(PulseSignal $signal, NotificationRule $rule): string
    {
        $template = $rule->subject_override
            ?: ($this->registry->get($signal->eventKey)?->defaultSubject() ?? $this->registry->label($signal->eventKey));

        return CrmEmailTemplate::render($template, $this->placeholders($signal));
    }

    /**
     * Шаблон письма: свой из правила, иначе шаблон события, иначе общий.
     *
     * Общий шаблон умеет блоки rows[], поэтому большинству событий свой
     * не нужен вовсе.
     */
    public function template(PulseSignal $signal, NotificationRule $rule): string
    {
        $candidates = array_filter([
            $rule->template_key,
            $this->registry->get($signal->eventKey)?->defaultTemplate(),
        ]);

        foreach ($candidates as $candidate) {
            if (view()->exists($candidate)) {
                return $candidate;
            }
        }

        return 'mail.pulse.default';
    }

    /**
     * Ссылка отписки для конкретного адресата.
     *
     * Токен создаётся лениво — при первой отправке: большинство получателей
     * правила писем так и не получат, потому что условия не совпадут.
     */
    public function unsubscribeUrl(ResolvedRecipient $recipient): ?string
    {
        $link = $recipient->rule->recipients
            ->firstWhere(fn ($item) => $item->kind === $recipient->kind
                && ($item->contact_id === $recipient->contactId || $recipient->contactId === null));

        if ($link === null) {
            return null;
        }

        return url(route('subscriptions.unsubscribe', $link->ensureUnsubscribeToken(), false));
    }

    /**
     * Значения плейсхолдеров темы. Набор закрытый и намеренно короткий.
     *
     * @return array<string, string>
     */
    private function placeholders(PulseSignal $signal): array
    {
        $data = $signal->data;
        $view = $signal->view;

        return [
            'order_number' => (string) ($data['order_number'] ?? $view['entity_label'] ?? ''),
            'status_label' => (string) ($data['status_label'] ?? ''),
            'client_name' => (string) ($data['client_name'] ?? ''),
            'company_name' => (string) ($data['company_name'] ?? ''),
            'amount' => (string) ($data['total'] ?? $data['amount'] ?? ''),
        ];
    }
}
