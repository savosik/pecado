<?php

namespace App\Support;

/**
 * Ссылка на ролик → адрес для встраивания (iframe).
 *
 * Поддерживаются площадки, которыми реально пользуются: YouTube, Rutube,
 * VK Видео, Vimeo. Ссылка на что-то другое встраивания не получает — и
 * форма её не примет, чтобы читатель не увидел пустую рамку.
 */
final class VideoEmbed
{
    public static function from(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $parts['host']));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        return match (true) {
            $host === 'youtube.com' || $host === 'm.youtube.com' => self::youtube($path, $query),
            $host === 'youtu.be' => self::youtubeId(ltrim($path, '/')),
            $host === 'rutube.ru' => self::rutube($path),
            $host === 'vk.com' || $host === 'vkvideo.ru' || $host === 'm.vk.com' => self::vk($path, $query),
            $host === 'vimeo.com' || $host === 'player.vimeo.com' => self::vimeo($path),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function youtube(string $path, array $query): ?string
    {
        if (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
            return self::youtubeId($m[1]);
        }

        return self::youtubeId((string) ($query['v'] ?? ''));
    }

    private static function youtubeId(string $id): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) ? 'https://www.youtube.com/embed/'.$id : null;
    }

    private static function rutube(string $path): ?string
    {
        if (preg_match('#^/(?:video|play/embed)/([a-f0-9]{16,})#i', $path, $m)) {
            return 'https://rutube.ru/play/embed/'.$m[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function vk(string $path, array $query): ?string
    {
        // vk.com/video-123_456 и vkvideo.ru/video-123_456
        if (preg_match('#/video(-?\d+)_(\d+)#', $path, $m)) {
            return sprintf('https://vk.com/video_ext.php?oid=%s&id=%s', $m[1], $m[2]);
        }

        // Уже адрес встраивания: vk.com/video_ext.php?oid=…&id=…
        if (str_starts_with($path, '/video_ext.php') && isset($query['oid'], $query['id'])) {
            return sprintf('https://vk.com/video_ext.php?oid=%s&id=%s', (int) $query['oid'], (int) $query['id']);
        }

        return null;
    }

    private static function vimeo(string $path): ?string
    {
        if (preg_match('#/(?:video/)?(\d{6,})#', $path, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }
}
