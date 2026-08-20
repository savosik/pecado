<?php

namespace App\Notifications\Pulse\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Описание произошедшего — вход движка.
 *
 * Каналонезависимо: что случилось, у кого, с какими числами. Кому это
 * отправлять, решают правила, а не тот, кто сигнал породил.
 *
 * По смыслу совместим с существующим EntityChangeNotice: `view.rows`
 * принимает те же блоки diff/action/note, поэтому вёрстка писем переиспользуется.
 */
class PulseSignal
{
    /**
     * @param  string  $eventKey  ключ события из реестра
     * @param  array<string, mixed>  $data  поля, доступные условиям правил
     * @param  array<string, mixed>  $view  данные для вёрстки письма (title, body, url, rows)
     * @param  array<int, string>  $attachments  пути к файлам вложений
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly ?int $clientUserId = null,
        public readonly ?int $companyId = null,
        public readonly ?Model $subject = null,
        public readonly array $data = [],
        public readonly array $view = [],
        public readonly array $attachments = [],
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly string $uuid = '',
    ) {}

    public function withUuid(): self
    {
        if ($this->uuid !== '') {
            return $this;
        }

        return new self(
            $this->eventKey,
            $this->clientUserId,
            $this->companyId,
            $this->subject,
            $this->data,
            $this->view,
            $this->attachments,
            $this->occurredAt,
            (string) Str::uuid(),
        );
    }

    /**
     * Дополнить контекст вычисленными полями (ИНН контрагента, менеджер и т.п.).
     *
     * @param  array<string, mixed>  $extra
     */
    public function withData(array $extra): self
    {
        return new self(
            $this->eventKey,
            $this->clientUserId,
            $this->companyId,
            $this->subject,
            array_merge($this->data, $extra),
            $this->view,
            $this->attachments,
            $this->occurredAt,
            $this->uuid,
        );
    }

    public function occurredAtOrNow(): CarbonInterface
    {
        return $this->occurredAt ?? now();
    }
}
