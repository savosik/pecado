<?php

namespace App\Notifications\Returns;

use App\Enums\ReturnStatus;
use App\Models\ProductReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReturnStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public ProductReturn $productReturn,
        public ?ReturnStatus $previousStatus = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->productReturn->erp_number ?: '№'.$this->productReturn->id;
        $name = trim((string) ($notifiable->name ?? '')) ?: 'друг';

        return (new MailMessage)
            ->subject(sprintf('Возврат %s: %s — Pecado.ru', $number, $this->productReturn->status->label()))
            ->markdown('mail.returns.status-changed', [
                'productReturn' => $this->productReturn,
                'returnNumber' => $number,
                'name' => $name,
                'newStatus' => $this->productReturn->status,
                'previousStatus' => $this->previousStatus,
                'message' => $this->humanMessage(),
                'returnUrl' => url(route('cabinet.returns.show', $this->productReturn, false)),
            ]);
    }

    private function humanMessage(): string
    {
        return match ($this->productReturn->status) {
            ReturnStatus::FOR_RETURN => 'Заявка согласована и принята к возврату.',
            ReturnStatus::IN_RESERVE => 'Товары зарезервированы. Скоро мы пригласим вас передать их или организуем забор.',
            ReturnStatus::READY_FOR_SHIPMENT => 'Возврат готов к отгрузке от вас. Менеджер согласует с вами способ передачи.',
            ReturnStatus::COMPLETED => 'Возврат выполнен. Денежные средства будут зачислены на ваш баланс согласно условиям договора.',
            ReturnStatus::REJECTED => 'К сожалению, заявка отклонена. Подробности — в кабинете либо у вашего менеджера.',
            default => 'Статус вашей заявки на возврат обновлён. Подробности — в личном кабинете.',
        };
    }
}
