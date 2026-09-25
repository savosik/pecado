<?php

namespace Tests\Unit\Support;

use App\Support\VideoEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VideoEmbedTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function links(): array
    {
        return [
            'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'youtube shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'rutube video' => ['https://rutube.ru/video/0123456789abcdef0123456789abcdef/?r=wd', 'https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef'],
            'rutube embed' => ['https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef', 'https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef'],
            'vk video' => ['https://vk.com/video-12345_67890', 'https://vk.com/video_ext.php?oid=-12345&id=67890'],
            'vkvideo.ru' => ['https://vkvideo.ru/video-12345_67890', 'https://vk.com/video_ext.php?oid=-12345&id=67890'],
            'vk ext' => ['https://vk.com/video_ext.php?oid=-12345&id=67890&hd=2', 'https://vk.com/video_ext.php?oid=-12345&id=67890'],
            'vimeo' => ['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789'],
            'empty' => ['', null],
            'null' => [null, null],
            'unknown host' => ['https://example.com/video.mp4', null],
            'youtube without id' => ['https://www.youtube.com/feed/subscriptions', null],
            'garbage' => ['not a url', null],
        ];
    }

    #[Test]
    #[DataProvider('links')]
    public function it_turns_share_links_into_embed_urls(?string $url, ?string $expected): void
    {
        $this->assertSame($expected, VideoEmbed::from($url));
    }
}
