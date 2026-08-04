<?php

namespace App\Models\Concerns;

use App\Support\Crm\CrmSource;
use Illuminate\Database\Eloquent\Model;

/**
 * Проставляет источник записи (человек или ИИ-агент) при создании.
 *
 * Трейт, а не строчка в каждом контроллере: запись создаётся из интерфейса,
 * из REST-гейта и из MCP, и достаточно одного забытого места, чтобы в ленте
 * клиента появилась запись неизвестного происхождения. Модель — единственная
 * точка, через которую проходят все пути создания.
 *
 * Поле не входит в fillable намеренно: источник определяется контекстом запроса,
 * а не тем, что прислал вызывающий — иначе агент мог бы представиться человеком.
 */
trait RecordsCrmSource
{
    public static function bootRecordsCrmSource(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('source') === null) {
                $model->setAttribute('source', CrmSource::current());
            }
        });
    }

    /**
     * Запись сделана ИИ-агентом.
     */
    public function isFromAgent(): bool
    {
        return $this->getAttribute('source') === CrmSource::AGENT;
    }
}
