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

    public const STATUS_STALE = 'stale';

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

    /**
     * Сообщение корректно, но не применено: оно собрано в 1С раньше данных, которые
     * сайт уже принял (v16.16.0, снимки ожидаемых поступлений).
     *
     * Для документов с `revision` то же решение принимает {@see ErpRevisionGuard} ещё
     * до обработчика. Здесь свежесть сравнивается под блокировкой строки внутри
     * транзакции обработчика, поэтому исход сообщает он сам. В журнале шины статус
     * тот же — `stale`: 1С должна видеть, что её сообщение не применено.
     */
    public function markStale(string $message): void
    {
        $this->status = self::STATUS_STALE;
        $this->message = $message;
    }

    /**
     * Сообщение применено, но с оговоркой, о которой должна узнать 1С (v16.14.0).
     *
     * Статус остаётся `success` — данные приняты, — а текст попадает в журнал шины рядом
     * с сообщением. Пример: `measurement.state = done` без `revision` сайт сохранил как
     * `pending`. Оговорок может быть несколько, они склеиваются.
     */
    public function addNote(string $message): void
    {
        $this->message = $this->message === null ? $message : $this->message.' '.$message;
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
