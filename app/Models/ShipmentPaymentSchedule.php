<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Строка графика оплаты реализации — плановая дата и сумма платежа.
 *
 * Приезжает из 1С массивом `payment_schedule` внутри самого документа реализации
 * (табличная часть «Правила оплаты»), поэтому связь — обычный FK на реализацию,
 * а не uuid, как в PaymentAllocation: строк-сирот здесь не бывает.
 *
 * Статус строки намеренно НЕ хранится: «ожидается / частично / оплачена» и признак
 * просрочки — функция от `paid_amount` и текущей даты. Хранимый статус пришлось бы
 * пересчитывать ночным заданием, и он врал бы до следующего запуска.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int|null $line_number
 * @property \Illuminate\Support\Carbon|null $due_date В БД колонка NOT NULL, но у несохранённой модели атрибута ещё нет
 * @property numeric $amount
 * @property numeric $paid_amount
 * @property numeric|null $percent
 * @property int|null $term_days
 * @property string|null $basis
 * @property string|null $basis_name
 * @property string|null $stage
 * @property string|null $stage_name
 * @property string|null $order_uuid
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read \App\Models\Order|null $order
 * @property-read float $unpaid_amount
 * @property-read string $status
 * @property-read string $status_label
 * @property-read bool $is_overdue
 *
 * @method static \Database\Factories\ShipmentPaymentScheduleFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class ShipmentPaymentSchedule extends Model
{
    use HasFactory;

    /** Строка ещё не закрыта ни одним платежом. */
    public const STATUS_PENDING = 'pending';

    /** Строка закрыта частично. */
    public const STATUS_PARTIAL = 'partial';

    /** Строка закрыта полностью. */
    public const STATUS_PAID = 'paid';

    /**
     * Погрешность сравнения денег. Такая же, как у Payment::EPSILON — сравнивать
     * decimal через == нельзя, а разъехавшиеся допуски дали бы строку,
     * «оплаченную» в одном разделе и «недоплаченную» в другом.
     */
    public const EPSILON = 0.01;

    /** @var list<string> */
    public const BASES = ['shipment_date', 'order_date', 'invoice_date'];

    /** @var list<string> */
    public const STAGES = ['prepayment', 'advance', 'credit'];

    protected $fillable = [
        'shipment_id',
        'line_number',
        'due_date',
        'amount',
        'paid_amount',
        'percent',
        'term_days',
        'basis',
        'basis_name',
        'stage',
        'stage_name',
        'order_uuid',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'percent' => 'decimal:4',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Заказ из строки графика — мягкая связь по uuid, как у shipment_items.
     * FK нет: заказ мог не приехать на сайт.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }

    /**
     * Порядок погашения и показа: сначала по плановой дате, при равных датах —
     * по номеру строки из 1С. Тот же порядок использует FIFO-раскладка,
     * поэтому он вынесен в скоуп, а не продублирован по вызовам.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInFifoOrder(Builder $query): void
    {
        $query->orderBy('due_date')->orderByRaw('COALESCE(line_number, 2147483647)')->orderBy('id');
    }

    /**
     * Строки, по которым ещё ждём денег.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        // Константа подставляется в SQL, а не биндится параметром: у выражения
        // `amount - paid_amount` нет аффинности типа, и SQLite сравнил бы число
        // с привязанной строкой '0.01' — а текст там всегда больше числа,
        // из-за чего условие не находило бы ни одной строки.
        $query->whereRaw('amount - paid_amount > '.self::EPSILON);
    }

    /**
     * Остаток к оплате по строке. Переплата остатка не создаёт — отсюда max(0, …).
     */
    public function getUnpaidAmountAttribute(): float
    {
        return max(0.0, round((float) $this->amount - (float) $this->paid_amount, 2));
    }

    /**
     * Состояние строки — производное от погашенной суммы.
     */
    public function getStatusAttribute(): string
    {
        $paid = (float) $this->paid_amount;
        $amount = (float) $this->amount;

        if ($paid >= $amount - self::EPSILON) {
            return self::STATUS_PAID;
        }

        return $paid > self::EPSILON ? self::STATUS_PARTIAL : self::STATUS_PENDING;
    }

    /**
     * Метка состояния на русском. Просрочка — отдельный признак, а не статус:
     * просроченной может быть и неоплаченная, и частично оплаченная строка.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'Оплачено',
            self::STATUS_PARTIAL => 'Оплачено частично',
            default => $this->is_overdue ? 'Просрочено' : 'Ожидается',
        };
    }

    /**
     * Плановая дата прошла, а деньги не пришли.
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === self::STATUS_PAID || ! $this->due_date instanceof Carbon) {
            return false;
        }

        return $this->due_date->startOfDay()->isBefore(Carbon::today());
    }
}
