<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanMediaTempFiles extends Command
{
    protected $signature = 'media:clean-temp {--minutes=60 : Удалять файлы старше N минут}';

    protected $description = 'Удалить временные директории media-library* из системного temp (накапливаются при сбоях upload в S3)';

    public function handle(): int
    {
        $tempDir = sys_get_temp_dir();
        $cutoff = time() - ($this->option('minutes') * 60);

        $deleted = 0;
        $bytesFreed = 0;

        foreach (glob($tempDir.'/media-library*') ?: [] as $path) {
            if (! is_dir($path)) {
                continue;
            }

            if (filemtime($path) > $cutoff) {
                continue;
            }

            $bytesFreed += $this->dirSize($path);
            $this->rrmdir($path);
            $deleted++;
        }

        $mb = round($bytesFreed / 1024 / 1024, 2);
        $message = "media:clean-temp: удалено {$deleted} директорий, освобождено {$mb} MB";

        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }

    private function dirSize(string $path): int
    {
        $size = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function rrmdir(string $path): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
