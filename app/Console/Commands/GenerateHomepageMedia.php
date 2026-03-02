<?php

namespace App\Console\Commands;

use App\Models\Banner;
use App\Models\StorySlide;
use Illuminate\Console\Command;

class GenerateHomepageMedia extends Command
{
    protected $signature = 'app:generate-homepage-media';
    protected $description = 'Генерация placeholder-изображений для баннеров и сторис';

    /**
     * Градиентные палитры для разных тем.
     */
    private array $gradients = [
        ['#FF6B6B', '#EE5A24'],  // красный-оранжевый
        ['#A29BFE', '#6C5CE7'],  // фиолетовый
        ['#FD79A8', '#E84393'],  // розовый
        ['#00CEC9', '#0984E3'],  // бирюзовый-синий
        ['#FDCB6E', '#E17055'],  // жёлтый-оранжевый
        ['#55EFC4', '#00B894'],  // мятный
        ['#74B9FF', '#0984E3'],  // голубой
        ['#DFE6E9', '#636E72'],  // серый
        ['#FAB1A0', '#E17055'],  // персиковый
        ['#81ECEC', '#00CEC9'],  // аква
        ['#FF7675', '#D63031'],  // красный
        ['#FFEAA7', '#FDCB6E'],  // золотой
        ['#C4A7FF', '#8B5CF6'],  // лавандовый
        ['#F093FB', '#F5576C'],  // розово-красный
        ['#4FACFE', '#00F2FE'],  // небесный
        ['#43E97B', '#38F9D7'],  // зелёный
        ['#FA709A', '#FEE140'],  // закат
        ['#A18CD1', '#FBC2EB'],  // пастельный фиолетовый
        ['#FF9A9E', '#FECFEF'],  // нежно-розовый
        ['#667EEA', '#764BA2'],  // индиго
        ['#F7971E', '#FFD200'],  // оранжево-жёлтый
        ['#B721FF', '#21D4FD'],  // пурпурный-голубой
        ['#08AEEA', '#2AF598'],  // синий-зелёный
        ['#FFC3A0', '#FFAFBD'],  // коралловый
    ];

    public function handle(): int
    {
        $this->info('🎨 Генерация изображений для главной страницы...');
        $this->newLine();

        $this->generateBannerImages();
        $this->generateStorySlideImages();

        $this->newLine();
        $this->info('✅ Готово! Не забудьте сбросить кеш: php artisan cache:clear');

        return Command::SUCCESS;
    }

    private function generateBannerImages(): void
    {
        $banners = Banner::all();
        $this->info("📢 Баннеры ({$banners->count()}):");

        foreach ($banners as $i => $banner) {
            $gradient = $this->gradients[$i % count($this->gradients)];

            // Desktop: 1400x400
            $desktopPath = $this->createGradientImage(
                1400, 400, $gradient[0], $gradient[1], $banner->title, 48
            );
            $banner->clearMediaCollection('desktop');
            $banner->addMedia($desktopPath)
                ->usingFileName("banner_{$banner->id}_desktop.png")
                ->toMediaCollection('desktop');

            // Mobile: 800x400
            $mobilePath = $this->createGradientImage(
                800, 400, $gradient[0], $gradient[1], $banner->title, 36
            );
            $banner->clearMediaCollection('mobile');
            $banner->addMedia($mobilePath)
                ->usingFileName("banner_{$banner->id}_mobile.png")
                ->toMediaCollection('mobile');

            $this->line("  ✓ Баннер #{$banner->id}: {$banner->title}");
        }
    }

    private function generateStorySlideImages(): void
    {
        $slides = StorySlide::with('story')->orderBy('story_id')->orderBy('sort_order')->get();
        $this->info("📱 Сторис-слайды ({$slides->count()}):");

        $storyIndex = 0;
        $prevStoryId = null;

        foreach ($slides as $slide) {
            if ($slide->story_id !== $prevStoryId) {
                $storyIndex++;
                $prevStoryId = $slide->story_id;
            }

            $gradient = $this->gradients[($storyIndex - 1) % count($this->gradients)];

            // Story slides: 1080x1920 (vertical format, like Instagram stories)
            $path = $this->createGradientImage(
                1080, 1920, $gradient[0], $gradient[1],
                $slide->title ?: ($slide->story->name ?? ''),
                40,
                true  // vertical
            );

            $slide->clearMediaCollection('default');
            $slide->addMedia($path)
                ->usingFileName("slide_{$slide->id}.png")
                ->toMediaCollection('default');

            $storyName = $slide->story->name ?? '?';
            $this->line("  ✓ [{$storyName}] Слайд #{$slide->id}: {$slide->title}");
        }
    }

    /**
     * Создаёт PNG-изображение с градиентным фоном и текстом.
     */
    private function createGradientImage(
        int    $width,
        int    $height,
        string $colorFrom,
        string $colorTo,
        string $text,
        int    $fontSize = 40,
        bool   $vertical = false
    ): string {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Parse hex colors
        $from = $this->hexToRgb($colorFrom);
        $to = $this->hexToRgb($colorTo);

        // Draw gradient
        if ($vertical) {
            for ($y = 0; $y < $height; $y++) {
                $ratio = $y / max($height - 1, 1);
                $r = (int)($from[0] + ($to[0] - $from[0]) * $ratio);
                $g = (int)($from[1] + ($to[1] - $from[1]) * $ratio);
                $b = (int)($from[2] + ($to[2] - $from[2]) * $ratio);
                $color = imagecolorallocate($image, $r, $g, $b);
                imageline($image, 0, $y, $width - 1, $y, $color);
            }
        } else {
            for ($x = 0; $x < $width; $x++) {
                $ratio = $x / max($width - 1, 1);
                $r = (int)($from[0] + ($to[0] - $from[0]) * $ratio);
                $g = (int)($from[1] + ($to[1] - $from[1]) * $ratio);
                $b = (int)($from[2] + ($to[2] - $from[2]) * $ratio);
                $color = imagecolorallocate($image, $r, $g, $b);
                imageline($image, $x, 0, $x, $height - 1, $color);
            }
        }

        // Add decorative circles for visual interest
        $this->addDecorativeElements($image, $width, $height);

        // Add text
        $white = imagecolorallocate($image, 255, 255, 255);
        $shadow = imagecolorallocate($image, 0, 0, 0);

        // Use built-in font for maximum compatibility
        $this->drawCenteredText($image, $text, $width, $height, $white, $shadow, $fontSize);

        // Save to temp file
        $path = tempnam(sys_get_temp_dir(), 'pecado_img_') . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function addDecorativeElements($image, int $width, int $height): void
    {
        // Semi-transparent white circles
        for ($i = 0; $i < 5; $i++) {
            $cx = rand(0, $width);
            $cy = rand(0, $height);
            $radius = rand(min($width, $height) / 8, min($width, $height) / 3);

            $overlay = imagecolorallocatealpha($image, 255, 255, 255, 115); // very transparent
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $overlay);
        }
    }

    private function drawCenteredText($image, string $text, int $width, int $height, $white, $shadow, int $fontSize): void
    {
        // Scale font size for GD built-in fonts (1-5)
        $gdFont = 5; // largest built-in font

        // Word wrap text
        $maxCharsPerLine = max(10, intdiv($width, 12));
        $lines = explode("\n", wordwrap($text, $maxCharsPerLine, "\n", true));

        $lineHeight = imagefontheight($gdFont) + 4;
        $totalHeight = count($lines) * $lineHeight;
        $startY = (int)(($height - $totalHeight) / 2);

        foreach ($lines as $i => $line) {
            $lineWidth = imagefontwidth($gdFont) * mb_strlen($line);
            $x = (int)(($width - $lineWidth) / 2);
            $y = $startY + $i * $lineHeight;

            // Shadow
            imagestring($image, $gdFont, $x + 2, $y + 2, $line, $shadow);
            // Text
            imagestring($image, $gdFont, $x, $y, $line, $white);
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
