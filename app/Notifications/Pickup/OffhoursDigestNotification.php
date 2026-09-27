<?php

namespace App\Notifications\Pickup;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Утреннее письмо менеджеру: что произошло по его клиентам вечером и в субботу (pick-13). */
class OffhoursDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public const SECTION_TITLES = [
        'not_picked' => 'Собраны и не забраны',
        'review' => 'Требуют разбора со складом',
        'reserve_lost' => 'Резервы, которые клиент потерял',
        'sent' => 'Отправлены на склад',
        'ready' => 'Собраны',
        'handed' => 'Выданы курьерам',
    ];

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    public function __construct(
        public array $sections,
        public int $total,
        public string $periodLabel,
        public ?string $onBehalfOf = null,
        public bool $department = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ordered = [];
        foreach (array_keys(self::SECTION_TITLES) as $key) {
            if (! empty($this->sections[$key])) {
                $ordered[self::SECTION_TITLES[$key]] = $this->sections[$key];
            }
        }

        return (new MailMessage)
            ->subject($this->department
                ? 'Пока отдел не работал: самовывоз и резервы клиентов — Pecado.ru'
                : 'Пока вас не было: самовывоз и резервы ваших клиентов — Pecado.ru')
            ->markdown('mail.pickup.offhours-digest', [
                'sections' => $ordered,
                'periodLabel' => $this->periodLabel,
                'onBehalfOf' => $this->onBehalfOf,
                'department' => $this->department,
                'notPicked' => count($this->sections['not_picked'] ?? []),
            ]);
    }
}
