<?php

namespace App\Jobs;

use App\Enums\Crm\EmailStatus;
use App\Listeners\RecordMailBounce;
use App\Mail\CrmManagerMail;
use App\Models\CrmEmail;
use App\Services\Crm\Mail\MailDeliveryLedger;
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

    public function handle(MailDeliveryLedger $ledger): void
    {
        // Письмо могли удалить, пока задание ждало очереди.
        $email = CrmEmail::query()->find($this->email->getKey());

        if ($email === null || $email->status === EmailStatus::SENT) {
            return;
        }

        // Адреса занимаются до отправки. Это единственное место, где решается
        // «уже отправляли»: повторная попытка задания, второй клик по самолётику
        // и правило, поймавшее письмо вслед за другим, приходят сюда одинаково,
        // а клиент не должен получить два одинаковых письма ни в одном случае.
        $recipients = $ledger->claim($email, (array) $email->to);

        if ($recipients === []) {
            // Всем адресам письмо уже уходило — задание отработало вхолостую,
            // и это успех, а не ошибка.
            $email->status = EmailStatus::SENT;
            $email->sent_at ??= now();
            $email->error = null;
            $email->save();

            return;
        }

        Mail::to($recipients)->send(new CrmManagerMail($email));

        $ledger->markSent($email, $recipients);

        // message_id дописывает слушатель MessageSent — здесь его ещё нет.
        $email->status = EmailStatus::SENT;
        $email->sent_at = now();
        $email->error = null;
        $email->save();
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception === null
            ? 'Неизвестная ошибка отправки'
            : mb_substr($exception->getMessage(), 0, 2000);

        CrmEmail::query()
            ->whereKey($this->email->getKey())
            ->update([
                'status' => EmailStatus::FAILED->value,
                'error' => $error,
            ]);

        // Несуществующий адрес попадает в стоп-лист: иначе он отбивается
        // на каждом письме, а репутация отправителя падает для всей почты
        // домена, включая письма о заказах.
        app(RecordMailBounce::class)->handleFailure((array) $this->email->to, $error);
    }
}
