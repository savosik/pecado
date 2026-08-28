<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'prices-exchange' => [
            'driver' => 's3',
            'key' => env('PRICES_S3_ACCESS_KEY', 'sail'),
            'secret' => env('PRICES_S3_SECRET_KEY', 'password'),
            'region' => env('PRICES_S3_REGION', 'us-east-1'),
            'bucket' => env('PRICES_S3_BUCKET', 'prices-exchange'),
            'endpoint' => env('PRICES_S3_ENDPOINT', 'http://minio:9000'),
            'use_path_style_endpoint' => true,
            'throw' => true,
        ],

        // Обменный бакет печатных форм (v16.1.0): 1С кладёт PDF, сайт забирает
        // и удаляет исходник. Отдельно от prices-exchange намеренно — у того свой
        // клинер app:clean-price-dumps с горизонтом в трое суток, и печатная форма,
        // не успевшая перенестись, была бы им снесена.
        // throw => true: молчаливый провал чтения означал бы потерянный документ,
        // а перезалить его неоткуда — 1С печатные формы не хранит.
        'documents-exchange' => [
            'driver' => 's3',
            'key' => env('DOCUMENTS_EXCHANGE_S3_ACCESS_KEY', 'sail'),
            'secret' => env('DOCUMENTS_EXCHANGE_S3_SECRET_KEY', 'password'),
            'region' => env('DOCUMENTS_EXCHANGE_S3_REGION', 'us-east-1'),
            'bucket' => env('DOCUMENTS_EXCHANGE_S3_BUCKET', 'documents-exchange'),
            'endpoint' => env('DOCUMENTS_EXCHANGE_S3_ENDPOINT', 'http://minio:9000'),
            'use_path_style_endpoint' => true,
            'throw' => true,
        ],

        // Долговременное приватное хранилище печатных форм (v16.1.0).
        // Ключи знает только сайт, у 1С доступа сюда нет.
        //
        // Файл отдаётся ТОЛЬКО через контроллер с проверкой прав. Публичная
        // ссылка на счёт-фактуру — утечка документов клиента любому, кто её
        // получил (см. Crm\AttachmentController::download).
        //
        // ВАЖНО: `Storage::disk('printed-documents')->url()` всё равно вернёт
        // строку — S3-драйвер собирает её из endpoint и bucket, даже когда поле
        // `url` не задано. Не полагайтесь на «диск не выдаёт ссылок»: защищает
        // не отсутствие URL, а приватность бакета — анонимный GET по нему даёт
        // 403. Бакет обязан оставаться private: `mc anonymous set public` на нём
        // мгновенно открывает все счета и УПД наружу.
        'printed-documents' => [
            'driver' => 's3',
            'key' => env('PRINTED_DOCUMENTS_S3_ACCESS_KEY', 'sail'),
            'secret' => env('PRINTED_DOCUMENTS_S3_SECRET_KEY', 'password'),
            'region' => env('PRINTED_DOCUMENTS_S3_REGION', 'us-east-1'),
            'bucket' => env('PRINTED_DOCUMENTS_S3_BUCKET', 'printed-documents'),
            'endpoint' => env('PRINTED_DOCUMENTS_S3_ENDPOINT', 'http://minio:9000'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
        ],

        // Сканы договоров и другие вложения CRM, которые нельзя выдавать по
        // публичной ссылке. Тот же MinIO и те же ключи, что у медиатеки, но
        // отдельный приватный бакет: анонимный GET по нему даёт 403, а файл
        // отдаётся только через контроллер с проверкой принадлежности.
        // Бакет создаёт `crm:contracts-private-storage` (он же переносит
        // ранее загруженные сканы с публичного диска).
        'crm-attachments' => [
            'driver' => 's3',
            'key' => env('CRM_ATTACHMENTS_S3_ACCESS_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('CRM_ATTACHMENTS_S3_SECRET_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('CRM_ATTACHMENTS_S3_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'bucket' => env('CRM_ATTACHMENTS_S3_BUCKET', 'crm-attachments'),
            'endpoint' => env('CRM_ATTACHMENTS_S3_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
        ],

        // Холодное хранилище архива лога шины ERP (Yandex Object Storage,
        // ледяной класс — там же, куда ночной скрипт кладёт бэкапы БД).
        // throw => true обязателен: команда удаляет строки из БД сразу после
        // заливки, и молчаливый провал записи означал бы потерю лога.
        // Отдельный диск, а не prices-exchange: у того свой клинер
        // app:clean-price-dumps, который снёс бы архивы старше трёх суток.
        'erp-archive' => [
            'driver' => 's3',
            'key' => env('ERP_ARCHIVE_S3_ACCESS_KEY'),
            'secret' => env('ERP_ARCHIVE_S3_SECRET_KEY'),
            'region' => env('ERP_ARCHIVE_S3_REGION', 'ru-central1'),
            'bucket' => env('ERP_ARCHIVE_S3_BUCKET'),
            'endpoint' => env('ERP_ARCHIVE_S3_ENDPOINT', 'https://storage.yandexcloud.net'),
            'use_path_style_endpoint' => false,
            'throw' => true,
            'report' => false,
        ],

        // Read-only диск, указывающий на dev MinIO.
        // Используется локальной разработкой (MEDIA_DISK=s3_dev_readonly),
        // чтобы видеть те же товарные изображения, что и dev-сервер,
        // не загрязняя shared dev-bucket новыми загрузками.
        's3_dev_readonly' => [
            'driver' => 's3',
            'key' => env('DEV_S3_KEY'),
            'secret' => env('DEV_S3_SECRET'),
            'region' => env('DEV_S3_REGION', 'us-east-1'),
            'bucket' => env('DEV_S3_BUCKET', 'pecado'),
            'endpoint' => env('DEV_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
