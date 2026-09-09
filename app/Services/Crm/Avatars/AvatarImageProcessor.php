<?php

namespace App\Services\Crm\Avatars;

use RuntimeException;

/**
 * Приведение картинки к аватарке: квадрат заданной стороны в WebP.
 *
 * На GD, без Intervention и spatie/image: задача ровно одна — центральный кроп
 * и пережатие, а тянуть ради неё зависимость (пусть даже транзитивную от
 * медиатеки) значит связать аватарки с чужим релизным циклом.
 *
 * Пережимается и то, что нарисовала модель (1024×1024 PNG весит под мегабайт,
 * а в списке аватарка 28 px), и то, что загрузил менеджер: заодно с файла
 * слетают EXIF с геометкой и camera-серийником, которым в CRM делать нечего.
 */
class AvatarImageProcessor
{
    /**
     * @param  string  $binary  исходный файл целиком
     * @return string WebP-квадрат
     *
     * @throws RuntimeException если это не картинка или GD её не понял
     */
    public function toSquareWebp(string $binary, ?int $size = null, ?int $quality = null): string
    {
        $size = $size ?? (int) config('crm_avatars.size', 256);
        $quality = $quality ?? (int) config('crm_avatars.quality', 82);

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            throw new RuntimeException('Файл не распознан как изображение.');
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            // Центральный квадрат: лицо у сгенерированных аватарок посередине,
            // а обрезка по краю превращала бы портрет в ухо.
            $side = min($width, $height);
            $srcX = (int) (($width - $side) / 2);
            $srcY = (int) (($height - $side) / 2);

            $canvas = imagecreatetruecolor($size, $size);

            // Прозрачность исходника не теряем: WebP её умеет, а без этих
            // двух строк PNG с альфой приезжает с чёрным фоном.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

            imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $size, $size, $side, $side);

            ob_start();
            $ok = imagewebp($canvas, null, $quality);
            $webp = (string) ob_get_clean();

            imagedestroy($canvas);

            if (! $ok || $webp === '') {
                throw new RuntimeException('Не удалось пережать изображение в WebP.');
            }

            return $webp;
        } finally {
            // @phpstan-ignore-next-line function.alreadyNarrowedType
            if (is_object($source) || is_resource($source)) {
                @imagedestroy($source);
            }
        }
    }
}
