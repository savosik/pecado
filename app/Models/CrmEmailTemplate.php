<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Заготовка письма с подстановками {{client_name}} и {{manager_name}}.
 *
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property string $body_html
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CrmEmailTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\CrmEmailTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'subject',
        'body_html',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Раскрыть подстановки.
     *
     * Список плейсхолдеров закрытый и раскрывается обычной заменой строк: Blade
     * или любой другой шаблонизатор здесь означал бы выполнение кода из базы.
     *
     * @param  array<string, string|null>  $values
     */
    public static function render(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }
}
