<?php

namespace App\Jobs;

use App\Enums\Crm\EmailStatus;
use App\Mail\CrmManagerMail;
use App\Models\CrmEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Отправка письма менеджера.
 *
 * Провал фиксируется в самой записи, а не только в логе очереди: менеджер, уверенный,
 * что отправил коммерческое предложение, обязан увидеть, что оно не ушло. Молча
 * потерянное письмо — это потерянная сделка, а не техническая мелочь.
 */
class SendCrmEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public CrmEmail $email) {}

    public function handle(): void
    {
        // Письмо могли удалить, пока задание ждало очереди.
        $email = CrmEmail::query()->find($this->email->getKey());

        if ($email === null || $email->status === EmailStatus::SENT) {
            return;
        }

        Mail::to($email->to)->send(new CrmManagerMail($email));

        // message_id дописывает слушатель MessageSent — здесь его ещё нет.
        $email->status = EmailStatus::SENT;
        $email->sent_at = now();
        $email->error = null;
        $email->save();
    }

    public function failed(?Throwable $exception): void
    {
        CrmEmail::query()
            ->whereKey($this->email->getKey())
            ->update([
                'status' => EmailStatus::FAILED->value,
                'error' => $exception === null
                    ? 'Неизвестная ошибка отправки'
                    : mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }
}
