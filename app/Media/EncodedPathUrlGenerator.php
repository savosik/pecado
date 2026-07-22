<?php

namespace App\Media;

use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * URL-генератор медиатеки с percent-encoding сегментов пути.
 *
 * Путь к файлам товара строится из кода 1С ({@see ProductPathGenerator}),
 * а он кириллический (`products-media/УТ-00007791/main/...`). Стандартный
 * DefaultUrlGenerator подставляет эти байты в URL как есть, без кодирования.
 *
 * Браузеры и curl такое проглатывают (кодируют на лету), но строгие HTTP-клиенты
 * (импортёры фидов на стороне клиентов, YML-валидатор Яндекс.Маркета и т.п.)
 * не-ASCII символы в URL по RFC 3986 не принимают — фото «не грузятся».
 *
 * Здесь мы percent-кодируем каждый сегмент пути (rawurlencode), сохраняя
 * слэши-разделители и базовый URL диска. ASCII-сегменты (имена файлов из hex,
 * `main`/`additional`) остаются неизменными; меняется только кириллица в коде.
 */
class EncodedPathUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        $url = $this->getDisk()->url($this->encodePath($this->getPathRelativeToRoot()));

        return $this->versionUrl($url);
    }

    public function getTemporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        // Временные (подписанные) URL кодирует сам AWS SDK при подписи ключа —
        // здесь вмешиваться нельзя, иначе сломается подпись.
        return parent::getTemporaryUrl($expiration, $options);
    }

    public function getResponsiveImagesDirectoryUrl(): string
    {
        $path = $this->pathGenerator->getPathForResponsiveImages($this->media);

        return Str::finish($this->getDisk()->url($this->encodePath($path)), '/');
    }

    /**
     * Percent-кодирует каждый сегмент пути, не трогая слэши-разделители.
     */
    protected function encodePath(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path),
        ));
    }
}
