<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Лог должен оставаться доступным на запись всем процессам проекта.
 *
 * В storage/logs пишут php-fpm (www-data) и воркеры с планировщиком (laravel),
 * а контейнер worker поднят от root. 14.09.2026 планировщик без `user=` создал
 * суточный лог как root:root 0644, и любой Log::* до полуночи бросал исключение:
 * 500 на входе под партнёром в CRM и падения задач в очередях.
 */
class LogWritabilityConfigTest extends TestCase
{
    public function test_daily_log_channels_are_shared_between_users(): void
    {
        $daily = collect(config('logging.channels'))
            ->filter(fn (array $channel) => ($channel['driver'] ?? null) === 'daily');

        $this->assertNotEmpty($daily);

        foreach ($daily as $name => $channel) {
            $this->assertSame(0666, $channel['permission'] ?? null, "Канал «{$name}» создаёт файл, закрытый для других пользователей.");
        }
    }

    #[DataProvider('supervisorConfigDirs')]
    public function test_supervisor_programs_do_not_run_as_root(string $dir): void
    {
        $files = glob(base_path("docker/supervisor/{$dir}/*.conf"));

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $programs = preg_split('/^(?=\[program:)/m', (string) file_get_contents($file));

            foreach ($programs as $program) {
                if (! preg_match('/^\[program:([^\]]+)\]/', $program, $m)) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/^user=laravel$/m',
                    $program,
                    basename($file)." → [program:{$m[1]}] запустится от root и запрёт лог для php-fpm.",
                );
            }
        }
    }

    public static function supervisorConfigDirs(): array
    {
        return [
            'dev и прод' => ['conf.d'],
            'локальный профиль' => ['conf.d.local'],
        ];
    }
}
