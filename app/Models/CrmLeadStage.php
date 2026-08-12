<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Стадия воронки лидов.
 *
 * Пользовательские данные, а не enum: жизненный статус клиента зашит в коде
 * семью значениями, а воронку лидов руководитель перестраивает под себя
 * без релиза.
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property int $position
 * @property bool $is_won
 * @property bool $is_lost
 * @property bool $is_active
 */
class CrmLeadStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'position',
        'is_won',
        'is_lost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class, 'stage_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnBoard(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }

    /**
     * Стадия, в которую попадает только что заведённый лид.
     */
    public static function first(): ?self
    {
        return static::query()->onBoard()->first();
    }

    /**
     * Стадия завершает воронку — выигрышем или проигрышем.
     */
    public function isTerminal(): bool
    {
        return $this->is_won || $this->is_lost;
    }
}
