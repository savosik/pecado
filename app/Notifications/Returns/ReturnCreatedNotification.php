<?php

namespace App\Notifications\Returns;

use App\Models\ProductReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReturnCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public ProductReturn $productReturn,
    ) {
        $this->productReturn->loadMissing(['items', 'user']);
    }

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
            ->subject(sprintf('Заявка на возврат %s принята — Pecado.ru', $number))
            ->markdown('mail.returns.created', [
                'productReturn' => $this->productReturn,
                'returnNumber' => $number,
                'name' => $name,
                'returnUrl' => url(route('cabinet.returns.show', $this->productReturn, false)),
            ]);
    }
}
