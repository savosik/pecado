<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Версия схемы расчёта зарплаты: какие компоненты входят и их умолчания.
 *
 * @property int $id
 * @property string $code
 * @property int $version
 * @property string $title
 * @property \Illuminate\Support\Carbon $effective_from
 * @property array<int, mixed> $components как хранится: [{key, enabled, defaults}]; нормализованную форму даёт orderedComponents()
 * @property int|null $author_id
 * @property string|null $comment
 * @property-read User|null $author
 */
class PayrollScheme extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollSchemeFactory> */
    use HasFactory;

    public const CODE_SALES = 'sales';

    protected $fillable = [
        'code',
        'version',
        'title',
        'effective_from',
        'components',
        'author_id',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'components' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Компоненты схемы в порядке применения, с нормализованной формой записи.
     *
     * @return list<array{key: string, enabled: bool, defaults: array<string, mixed>}>
     */
    public function orderedComponents(): array
    {
        $rows = [];

        foreach ((array) $this->components as $entry) {
            if (! is_array($entry) || ! isset($entry['key'])) {
                continue;
            }

            $rows[] = [
                'key' => (string) $entry['key'],
                'enabled' => (bool) ($entry['enabled'] ?? true),
                'defaults' => is_array($entry['defaults'] ?? null) ? $entry['defaults'] : [],
            ];
        }

        return $rows;
    }
}
