<?php

namespace App\Enums;

/**
 * Формат инструкции: чем она является для читателя.
 *
 * Один формат на инструкцию намеренно: «текст + PDF + видео» в одной карточке
 * означало бы три способа сказать одно и то же, и читатель не понимал бы,
 * что из этого актуально. Нужно несколько форматов — заводится несколько
 * инструкций.
 */
enum InstructionType: string
{
    case TEXT = 'text';
    case PDF = 'pdf';
    case VIDEO = 'video';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Текст',
            self::PDF => 'PDF',
            self::VIDEO => 'Видео',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TEXT => 'blue',
            self::PDF => 'red',
            self::VIDEO => 'purple',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
