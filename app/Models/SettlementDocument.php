<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Документ-регистратор регистра взаиморасчётов (v16.0.0).
 *
 * Служебная запись: одна строка на документ 1С, породивший движения. Хранит только
 * то, чего нет больше нигде, — состояние документа как целого.
 *
 * Нужна из-за отмены проведения. Отметку применённой ревизии естественно было бы
 * держать в самих движениях, но `settlement.reverted` их удаляет — и следующее
 * устаревшее `settlement.posted` воскресило бы отменённый документ, потому что
 * сравнивать стало бы не с чем. `is_reverted` переживает удаление движений.
 *
 * Не путать с документом на сайте: реализация, заказ и платёж — самостоятельные
 * сущности со своими таблицами. Здесь лежит и то, чего на сайте нет вовсе,
 * например отчёт комиссионера.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $applied_revision
 * @property int|null $applied_schedule_revision
 * @property string|null $document_kind
 * @property string|null $document_number
 * @property \Illuminate\Support\Carbon|null $document_date
 * @property bool $is_reverted
 * @property \Illuminate\Support\Carbon|null $last_posted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SettlementEntry> $entries
 *
 * @method static \Database\Factories\SettlementDocumentFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class SettlementDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'applied_revision',
        'applied_schedule_revision',
        'document_kind',
        'document_number',
        'document_date',
        'is_reverted',
        'last_posted_at',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'is_reverted' => 'boolean',
            'applied_revision' => 'integer',
            'applied_schedule_revision' => 'integer',
            'last_posted_at' => 'datetime',
        ];
    }

    /**
     * Движения, порождённые этим документом. Связь по uuid, а не по FK:
     * движение может приехать раньше, чем заведётся запись документа.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SettlementEntry::class, 'document_uuid', 'uuid');
    }
}
