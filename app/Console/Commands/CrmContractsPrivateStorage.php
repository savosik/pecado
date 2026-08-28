<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Приватное хранилище сканов договоров.
 *
 * Два дела, оба идемпотентные и безопасные для повторного запуска на деплое:
 * создать приватный бакет, если его ещё нет, и перенести сканы договоров,
 * загруженные раньше на общий публичный диск медиатеки.
 */
class CrmContractsPrivateStorage extends Command
{
    protected $signature = 'crm:contracts-private-storage {--dry-run : Только показать, ничего не переносить}';

    protected $description = 'Создать приватный бакет для сканов договоров и перенести туда файлы с публичного диска';

    public function handle(): int
    {
        $disk = Contract::attachmentsDisk();

        if (! $this->ensureBucket($disk)) {
            return self::FAILURE;
        }

        $pending = Media::query()
            ->where('model_type', Contract::class)
            ->where('disk', '<>', $disk)
            ->orderBy('id');

        $count = (clone $pending)->count();
        $this->info(sprintf('Диск «%s». Сканов договоров на других дисках: %d.', $disk, $count));

        if ($count === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $moved = 0;
        $failed = 0;

        foreach ($pending->cursor() as $media) {
            try {
                $this->move($media, $disk);
                $moved++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('#%d %s: %s', $media->getKey(), $media->file_name, $e->getMessage()));
            }
        }

        $this->info(sprintf('Перенесено: %d, с ошибкой: %d.', $moved, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Перенос файла между дисками с сохранением относительного пути.
     *
     * Путь строится PathGenerator-ом от id записи, поэтому одинаков на обоих
     * дисках — достаточно скопировать и переключить `disk`. Старый файл
     * удаляется только после успешной записи нового.
     */
    private function move(Media $media, string $target): void
    {
        $path = $media->getPathRelativeToRoot();
        $source = (string) $media->disk;

        $stream = Storage::disk($source)->readStream($path);

        if ($stream === null) {
            throw new \RuntimeException('файл не найден на исходном диске');
        }

        try {
            Storage::disk($target)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $media->forceFill(['disk' => $target, 'conversions_disk' => $target])->saveQuietly();

        Storage::disk($source)->delete($path);
    }

    private function ensureBucket(string $disk): bool
    {
        $storage = Storage::disk($disk);

        if (! $storage instanceof AwsS3V3Adapter) {
            // Локальный или подменённый в тестах диск: бакета нет по определению.
            return true;
        }

        $bucket = (string) config("filesystems.disks.{$disk}.bucket");
        $client = $storage->getClient();

        try {
            if ($client->doesBucketExistV2($bucket, true)) {
                return true;
            }

            $client->createBucket(['Bucket' => $bucket]);
            $this->info(sprintf('Создан приватный бакет «%s».', $bucket));

            return true;
        } catch (Throwable $e) {
            $this->error(sprintf('Бакет «%s» недоступен: %s', $bucket, $e->getMessage()));

            return false;
        }
    }
}
