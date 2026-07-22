<?php

namespace App\Services\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Управление REST-embedder-ом Meilisearch (векторный/гибридный поиск) через API.
 *
 * Инкапсулирует все прямые HTTP-вызовы к Meilisearch, связанные с эмбеддингами:
 *  - настройка embedder-а в индексе `products` (идемпотентно);
 *  - чтение текущих настроек embedder-а (проверка наличия / dimensions);
 *  - аудит документов без `_vectors` (товары, которым не досчитали вектор);
 *  - выборка упавших задач индексации (диагностика 402/OpenRouter).
 *
 * Используется командами `meilisearch:configure-embedders` и
 * `search:repair-embeddings`. `documentTemplate` и структура payload-а живут
 * здесь в единственном экземпляре.
 */
class MeilisearchEmbedderManager
{
    /**
     * Шаблон документа, из которого Meilisearch строит текст для эмбеддинга.
     * Обращается к ключам, присутствующим в Product::toSearchableArray().
     */
    public const DOCUMENT_TEMPLATE = 'Товар: {{doc.name}}. Бренд: {{doc.brand}}. Категория: {{doc.category}}. {{doc.description}}';

    private string $host;

    private ?string $key;

    private string $indexName = 'products';

    private string $embedderName;

    /** @var array<string, mixed> */
    private array $embedderConfig;

    public function __construct()
    {
        $this->host = rtrim((string) config('scout.meilisearch.host'), '/');
        $this->key = config('scout.meilisearch.key');
        $this->embedderName = (string) config('search.hybrid.embedder');
        $this->embedderConfig = (array) config('search.embedder');
    }

    public function indexName(): string
    {
        return $this->indexName;
    }

    public function embedderName(): string
    {
        return $this->embedderName;
    }

    public function isHybridEnabled(): bool
    {
        return (bool) config('search.hybrid.enabled');
    }

    public function hasApiKey(): bool
    {
        return ! empty($this->embedderConfig['api_key']);
    }

    public function configuredDimensions(): int
    {
        return (int) ($this->embedderConfig['dimensions'] ?? 0);
    }

    /**
     * Текущие настройки нашего embedder-а в индексе. Null — если embedder
     * в индексе не настроен (или запрос к Meilisearch не удался).
     *
     * @return array<string, mixed>|null
     */
    public function getEmbedder(): ?array
    {
        $response = $this->request()->get("{$this->host}/indexes/{$this->indexName}/settings/embedders");

        if (! $response->successful()) {
            return null;
        }

        $settings = (array) $response->json();

        return isset($settings[$this->embedderName]) ? (array) $settings[$this->embedderName] : null;
    }

    /**
     * Настроить (создать/обновить) embedder в индексе. Возвращает taskUid
     * или null при ошибке запроса.
     */
    public function configure(): ?int
    {
        $response = $this->request()->patch(
            "{$this->host}/indexes/{$this->indexName}/settings/embedders",
            $this->embedderPayload(),
        );

        if (! $response->successful()) {
            return null;
        }

        return $response->json('taskUid');
    }

    /**
     * Удалить embedder из индекса. Возвращает taskUid или null при ошибке.
     */
    public function reset(): ?int
    {
        $response = $this->request()->delete("{$this->host}/indexes/{$this->indexName}/settings/embedders");

        if (! $response->successful()) {
            return null;
        }

        return $response->json('taskUid');
    }

    /**
     * Payload настройки REST-embedder-а (source: rest → OpenRouter).
     *
     * @return array<string, mixed>
     */
    public function embedderPayload(): array
    {
        return [
            $this->embedderName => [
                'source' => 'rest',
                'url' => $this->embedderConfig['url'],
                'apiKey' => $this->embedderConfig['api_key'],
                'dimensions' => $this->configuredDimensions(),
                'documentTemplate' => self::DOCUMENT_TEMPLATE,
                'documentTemplateMaxBytes' => $this->embedderConfig['document_template_max_bytes'],
                'request' => [
                    'model' => $this->embedderConfig['model'],
                    'input' => ['{{text}}', '{{..}}'],
                ],
                'response' => [
                    'data' => [
                        ['embedding' => '{{embedding}}'],
                        '{{..}}',
                    ],
                ],
            ],
        ];
    }

    /**
     * ID документов индекса `products`, у которых отсутствует вектор
     * нашего embedder-а. Постранично тянет документы с retrieveVectors=true.
     *
     * @return array{missing: array<int, int>, total_scanned: int, index_total: int}
     */
    public function documentIdsMissingVectors(?int $limit = null): array
    {
        $missing = [];
        $scanned = 0;
        $indexTotal = 0;
        $offset = 0;
        $pageSize = 1000;

        while (true) {
            $response = $this->request()->get("{$this->host}/indexes/{$this->indexName}/documents", [
                'limit' => $pageSize,
                'offset' => $offset,
                'retrieveVectors' => 'true',
                'fields' => 'id',
            ]);

            if (! $response->successful()) {
                break;
            }

            $body = (array) $response->json();
            $indexTotal = (int) ($body['total'] ?? $indexTotal);
            $results = $body['results'] ?? [];

            if (empty($results)) {
                break;
            }

            foreach ($results as $doc) {
                $scanned++;

                if (self::documentLacksVector((array) $doc, $this->embedderName)) {
                    $missing[] = (int) $doc['id'];
                }

                if ($limit !== null && $scanned >= $limit) {
                    return ['missing' => $missing, 'total_scanned' => $scanned, 'index_total' => $indexTotal];
                }
            }

            if (count($results) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return ['missing' => $missing, 'total_scanned' => $scanned, 'index_total' => $indexTotal];
    }

    /**
     * Определить, что у документа нет вектора для указанного embedder-а.
     * Чистая функция — покрыта юнит-тестами.
     *
     * Формы `_vectors.<embedder>`:
     *  - отсутствует ключ           → нет вектора;
     *  - { embeddings: [...], ... } → нет вектора, если embeddings пуст;
     *  - [ [...] ] (сырой массив)   → нет вектора, если массив пуст.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function documentLacksVector(array $doc, string $embedderName): bool
    {
        $vectors = $doc['_vectors'][$embedderName] ?? null;

        if ($vectors === null) {
            return true;
        }

        if (is_array($vectors) && array_key_exists('embeddings', $vectors)) {
            return empty($vectors['embeddings']);
        }

        if (is_array($vectors)) {
            return count($vectors) === 0;
        }

        return true;
    }

    /**
     * Упавшие задачи индексации по индексу `products` (диагностика 402/OpenRouter).
     *
     * @return array<int, array<string, mixed>>
     */
    public function failedTasks(int $limit = 20): array
    {
        $response = $this->request()->get("{$this->host}/tasks", [
            'indexUids' => $this->indexName,
            'statuses' => 'failed',
            'limit' => $limit,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return (array) $response->json('results', []);
    }

    /**
     * Дождаться завершения задачи Meilisearch. Возвращает финальный статус
     * ('succeeded' | 'failed' | 'timeout') и текст ошибки, если есть.
     *
     * @return array{status: string, error: ?string}
     */
    public function waitForTask(int $taskUid, int $maxAttempts = 120, int $sleepSeconds = 5): array
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = $this->request()->get("{$this->host}/tasks/{$taskUid}");

            if (! $response->successful()) {
                return ['status' => 'unknown', 'error' => 'HTTP '.$response->status()];
            }

            $task = (array) $response->json();
            $status = $task['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                return ['status' => 'succeeded', 'error' => null];
            }

            if ($status === 'failed') {
                return ['status' => 'failed', 'error' => $task['error']['message'] ?? 'Неизвестная ошибка'];
            }

            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        }

        return ['status' => 'timeout', 'error' => null];
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(array_filter([
            'Authorization' => $this->key ? 'Bearer '.$this->key : null,
            'Content-Type' => 'application/json',
        ]));
    }
}
