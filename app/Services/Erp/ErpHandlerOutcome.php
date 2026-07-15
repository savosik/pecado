<?php

namespace App\Services\Erp;

/**
 * v15.4: Результат обработки входящего сообщения ERP.
 *
 * Обработчики (Handle*) возвращают void, поэтому у них не было способа сообщить
 * job-у, что обработка прошла нештатно. Через этот объект handler помечает исход,
 * а ErpIncomingJob кладёт его в лог шины (`erp_bus_messages.status`).
 *
 * Регистрируется singleton-ом: воркер живёт долго и обрабатывает много сообщений,
 * поэтому ErpIncomingJob обязан вызвать reset() перед каждым handle().
 */
class ErpHandlerOutcome
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_RECOVERED = 'recovered';

    private string $status = self::STATUS_SUCCESS;

    private ?string $message = null;

    /**
     * Сбросить состояние перед обработкой следующего сообщения.
     */
    public function reset(): void
    {
        $this->status = self::STATUS_SUCCESS;
        $this->message = null;
    }

    /**
     * Сообщение обработано, но потребовало восстановления сущности:
     * 1С потеряла событие создания, и сайт достроил её из этого payload.
     */
    public function markRecovered(string $message): void
    {
        $this->status = self::STATUS_RECOVERED;
        $this->message = $message;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
