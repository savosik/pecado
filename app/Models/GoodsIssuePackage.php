<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Грузовое место расходного ордера: один УпаковочныйЛист 1С = одна физическая коробка,
 * закрытая командой «Закрыть коробку» рабочего места упаковщика (US-20, v16.14.0).
 *
 * Единицы закреплены контрактом: вес — килограммы брутто, габариты — целые сантиметры
 * (внешний обмер). `volume` в v16.14.0 не передаётся и в расчётах не участвует: объём
 * считается по габаритам, см. {@see self::volumeM3()}.
 *
 * @property int $id
 * @property int $goods_issue_id
 * @property string|null $uuid GUID УпаковочногоЛиста — ключ идентичности места
 * @property int $number Отображаемый номер; может перенумероваться
 * @property string|null $barcode Code128 наклейки: десятичная строка переменной длины
 * @property string|null $package_type box|pallet|bag|roll|other
 * @property int|null $positions_count
 * @property numeric|null $weight Килограммы брутто
 * @property int|null $length Сантиметры
 * @property int|null $width Сантиметры
 * @property int|null $height Сантиметры
 * @property numeric|null $volume
 * @property-read string $package_type_label
 * @property-read \App\Models\GoodsIssue $goodsIssue
 *
 * @mixin \Eloquent
 */
class GoodsIssuePackage extends Model
{
    use HasFactory;

    public const TYPE_BOX = 'box';

    public const TYPE_PALLET = 'pallet';

    public const TYPE_BAG = 'bag';

    public const TYPE_ROLL = 'roll';

    public const TYPE_OTHER = 'other';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_BOX => 'Коробка',
        self::TYPE_PALLET => 'Паллета',
        self::TYPE_BAG => 'Мешок',
        self::TYPE_ROLL => 'Рулон',
        self::TYPE_OTHER => 'Прочее',
    ];

    protected $fillable = [
        'goods_issue_id',
        'uuid',
        'number',
        'barcode',
        'package_type',
        'positions_count',
        'weight',
        'length',
        'width',
        'height',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'volume' => 'decimal:3',
            'length' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<GoodsIssue, $this> */
    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class);
    }

    /** Нет значения = «прочее» — так договорено в контракте. */
    public function getPackageTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->package_type ?? self::TYPE_OTHER] ?? self::TYPE_LABELS[self::TYPE_OTHER];
    }

    /**
     * Место обмерено полностью: вес больше нуля и все три стороны не меньше сантиметра.
     *
     * Те же условия, что JSON Schema требует при `measurement.state = done`; сайт
     * проверяет их и у себя, чтобы расчёт не зависел от валидатора.
     */
    public function isMeasured(): bool
    {
        return (float) $this->weight > 0
            && (int) $this->length >= 1
            && (int) $this->width >= 1
            && (int) $this->height >= 1;
    }

    /** Вес в граммах — единица перевозчиков (ApiShip). */
    public function weightGrams(): int
    {
        return (int) round((float) $this->weight * 1000);
    }

    /** Объём по внешним габаритам, м³: Д×Ш×В / 1 000 000. null — место не обмерено. */
    public function volumeM3(): ?float
    {
        if (! $this->length || ! $this->width || ! $this->height) {
            return null;
        }

        return round($this->length * $this->width * $this->height / 1_000_000, 6);
    }
}
