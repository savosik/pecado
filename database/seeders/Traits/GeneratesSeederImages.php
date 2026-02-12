<?php

namespace Database\Seeders\Traits;

/**
 * Trait GeneratesSeederImages
 *
 * Общие методы для генерации изображений-заглушек через GD
 * (градиентный фон, декоративные круги, TTF-текст с кириллицей).
 */
trait GeneratesSeederImages
{
    /**
     * Генерирует баннер-изображение через GD с градиентным фоном.
     *
     * @param  string  $dir       Директория для сохранения
     * @param  string  $title     Заголовок (по центру)
     * @param  string  $subtitle  Подзаголовок
     * @param  array   $colors    Массив из 2–3 HEX-цветов для градиента
     * @param  string  $filename  Имя файла
     * @param  int     $width     Ширина изображения
     * @param  int     $height    Высота изображения
     * @return string|null        Путь к файлу или null при ошибке
     */
    protected function generateGdImage(
        string $dir,
        string $title,
        string $subtitle,
        array $colors,
        string $filename,
        int $width,
        int $height
    ): ?string {
        if (!function_exists('imagecreatetruecolor')) {
            $this->command?->warn('GD не установлен — изображения не будут созданы');
            return null;
        }

        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);

        // ── Градиентный фон ─────────────────────────────
        $c1 = $this->hexToRgb($colors[0]);
        $c2 = $this->hexToRgb($colors[1]);
        $c3 = $this->hexToRgb($colors[2] ?? $colors[1]);

        // Направление: горизонтальный для «широких» (w > h), вертикальный иначе
        $horizontal = $width > $height;
        $steps = $horizontal ? $width : $height;

        for ($s = 0; $s < $steps; $s++) {
            $ratio = $s / $steps;

            if ($ratio < 0.5) {
                $r2 = $ratio * 2;
                $r = (int)($c1['r'] + ($c2['r'] - $c1['r']) * $r2);
                $g = (int)($c1['g'] + ($c2['g'] - $c1['g']) * $r2);
                $b = (int)($c1['b'] + ($c2['b'] - $c1['b']) * $r2);
            } else {
                $r2 = ($ratio - 0.5) * 2;
                $r = (int)($c2['r'] + ($c3['r'] - $c2['r']) * $r2);
                $g = (int)($c2['g'] + ($c3['g'] - $c2['g']) * $r2);
                $b = (int)($c2['b'] + ($c3['b'] - $c2['b']) * $r2);
            }

            $color = imagecolorallocate($img, $r, $g, $b);

            if ($horizontal) {
                imageline($img, $s, 0, $s, $height, $color);
            } else {
                imageline($img, 0, $s, $width, $s, $color);
            }
        }

        // ── Декоративные круги ───────────────────────────
        $overlay = imagecolorallocatealpha($img, 255, 255, 255, 115);
        imagefilledellipse($img, (int)($width * 0.85), (int)($height * 0.3), (int)($height * 0.9), (int)($height * 0.9), $overlay);
        imagefilledellipse($img, (int)($width * 0.15), (int)($height * 0.75), (int)($height * 0.6), (int)($height * 0.6), $overlay);
        imagefilledellipse($img, (int)($width * 0.55), (int)($height * 0.15), (int)($height * 0.5), (int)($height * 0.5), $overlay);

        // ── Декоративные линии ───────────────────────────
        $lineOverlay = imagecolorallocatealpha($img, 255, 255, 255, 120);
        for ($i = 0; $i < 3; $i++) {
            $lx = (int)($width * (0.3 + $i * 0.15));
            imageline($img, $lx, 0, $lx + (int)($height * 0.3), $height, $lineOverlay);
        }

        // ── Текст ───────────────────────────────────────
        $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $useTtf = file_exists($fontBold);

        $white = imagecolorallocate($img, 255, 255, 255);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 60);

        if ($useTtf) {
            // Заголовок (по центру)
            $titleSize = max(16, (int)($height * 0.07));
            $this->drawCenteredTtfText($img, $titleSize, $fontBold, $title, $width, (int)($height * 0.48), $white, $shadow);

            // Подзаголовок
            if ($subtitle) {
                $subSize = max(10, (int)($height * 0.04));
                $lightGray = imagecolorallocate($img, 220, 220, 220);
                $this->drawCenteredTtfText($img, $subSize, $fontRegular, $subtitle, $width, (int)($height * 0.58), $lightGray, $shadow);
            }

            // Логотип «PECADO»
            $logoSize = max(10, (int)($height * 0.04));
            imagettftext($img, $logoSize, 0, 21, 15 + $logoSize, $shadow, $fontBold, 'PECADO');
            imagettftext($img, $logoSize, 0, 20, 15 + $logoSize, $white, $fontBold, 'PECADO');
        } else {
            // Fallback: GD built-in (без кириллицы)
            $titleFontSize = 5;
            $titleLen = mb_strlen($title);
            $titleX = max(10, (int)(($width - $titleLen * 9) / 2));
            $titleY = (int)($height * 0.40);
            imagestring($img, $titleFontSize, $titleX + 2, $titleY + 2, $title, $shadow);
            imagestring($img, $titleFontSize, $titleX, $titleY, $title, $white);
        }

        $path = "{$dir}/{$filename}";
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    /**
     * Рисует текст по центру горизонтально через imagettftext.
     */
    protected function drawCenteredTtfText($img, int $size, string $font, string $text, int $imgWidth, int $y, $color, $shadowColor = null): void
    {
        $bbox = imagettfbbox($size, 0, $font, $text);
        $textWidth = $bbox[2] - $bbox[0];
        $x = max(10, (int)(($imgWidth - $textWidth) / 2));

        if ($shadowColor !== null) {
            imagettftext($img, $size, 0, $x + 2, $y + 2, $shadowColor, $font, $text);
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    /**
     * Конвертировать HEX цвет в RGB.
     */
    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }
}
