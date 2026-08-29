<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала смен статусов партнёра.
 *
 * @property int $id
 * @property int $client_user_id
 * @property string $field
 * @property string|null $from_value
 * @property string $to_value
 * @property int|null $user_id
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $client
 * @property-read User|null $author
 */
class CrmClientStatusChange extends Model
{
    /** Жизненный статус партнёра: лид / активен / закрылся. */
    public const FIELD_LIFECYCLE = 'lifecycle';

    /**
     * Тип аккаунта (users.user_kind): партнёр / сотрудник / служебный.
     *
     * Живёт в том же журнале, что и жизненный статус: «этот аккаунт больше
     * не партнёр» — решение того же порядка, что «партнёр закрылся», и через
     * полгода вопрос «кто убрал его из базы» задают ровно так же.
     */
    public const FIELD_KIND = 'user_kind';

    /**
     * Страховой запас (users.stock_buffer_enabled): показывать ли клиенту
     * заниженные остатки по рисковым товарам.
     *
     * Тот же журнал: включение меняет то, что клиент видит на витрине,
     * и вопрос «кто и когда это включил» встанет ровно как со статусами.
     */
    public const FIELD_STOCK_BUFFER = 'stock_buffer';

    /**
     * Предзаказы (users.preorders_enabled): предлагать ли клиенту заказ
     * товара без остатка у поставщика.
     *
     * Выключить может и сам клиент в кабинете, и менеджер в CRM — в журнале
     * это различимо по автору (user_id), и вопрос «почему у него пропал
     * предзаказ» решается одной строкой.
     */
    public const FIELD_PREORDERS = 'preorders';

    protected $fillable = [
        'client_user_id',
        'field',
        'from_value',
        'to_value',
        'user_id',
        'reason',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
