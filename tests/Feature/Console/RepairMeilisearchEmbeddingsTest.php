<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepairMeilisearchEmbeddingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'scout.meilisearch.host' => 'http://meili.test:7700',
            'scout.meilisearch.key' => 'test-key',
            'search.hybrid.enabled' => true,
            'search.hybrid.embedder' => 'openrouter',
            'search.embedder' => [
                'url' => 'https://openrouter.ai/api/v1/embeddings',
                'api_key' => 'or-key',
                'model' => 'openai/text-embedding-3-large',
                'dimensions' => 3072,
                'document_template_max_bytes' => 800,
            ],
        ]);
    }

    public function test_выходит_если_гибрид_выключен(): void
    {
        config(['search.hybrid.enabled' => false]);

        $this->artisan('search:repair-embeddings')
            ->expectsOutputToContain('Гибридный поиск выключен')
            ->assertSuccessful();
    }

    public function test_падает_без_api_ключа(): void
    {
        config(['search.embedder.api_key' => null]);

        $this->artisan('search:repair-embeddings')
            ->assertFailed();
    }

    public function test_dry_run_отчёт_без_переиндексации(): void
    {
        Http::fake([
            '*/indexes/products/settings/embedders' => Http::response([
                'openrouter' => ['source' => 'rest', 'dimensions' => 3072],
            ], 200),
            '*/indexes/products/documents*' => Http::response([
                'total' => 2,
                'results' => [
                    ['id' => 100, '_vectors' => ['openrouter' => ['embeddings' => [[0.1]], 'regenerate' => true]]],
                    ['id' => 200], // без вектора
                ],
            ], 200),
            '*/tasks*' => Http::response(['results' => []], 200),
        ]);

        // В dry-run с --reindex-missing переиндексация не выполняется (только отчёт).
        $this->artisan('search:repair-embeddings', ['--dry-run' => true, '--reindex-missing' => true])
            ->expectsOutputToContain('Без вектора:     1')
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();
    }

    public function test_аудит_без_флага_ремонта_только_сообщает(): void
    {
        Http::fake([
            '*/indexes/products/settings/embedders' => Http::response([
                'openrouter' => ['source' => 'rest', 'dimensions' => 3072],
            ], 200),
            '*/indexes/products/documents*' => Http::response([
                'total' => 1,
                'results' => [['id' => 200]],
            ], 200),
            '*/tasks*' => Http::response(['results' => []], 200),
        ]);

        $this->artisan('search:repair-embeddings')
            ->expectsOutputToContain('Без вектора:     1')
            ->expectsOutputToContain('--reindex-missing')
            ->assertSuccessful();
    }

    public function test_сообщает_об_упавших_задачах(): void
    {
        Http::fake([
            '*/indexes/products/settings/embedders' => Http::response([
                'openrouter' => ['source' => 'rest', 'dimensions' => 3072],
            ], 200),
            '*/indexes/products/documents*' => Http::response([
                'total' => 1,
                'results' => [['id' => 200]],
            ], 200),
            '*/tasks*' => Http::response([
                'results' => [
                    ['uid' => 42, 'status' => 'failed', 'error' => ['message' => 'Insufficient credits']],
                ],
            ], 200),
        ]);

        $this->artisan('search:repair-embeddings')
            ->expectsOutputToContain('упавшие задачи')
            ->expectsOutputToContain('Insufficient credits')
            ->assertSuccessful();
    }

    public function test_настраивает_embedder_если_его_нет(): void
    {
        Http::fake([
            '*/indexes/products/settings/embedders' => Http::sequence()
                ->push([], 200)                       // getEmbedder → нет embedder-а
                ->push(['taskUid' => 7], 202),        // configure → PATCH
            '*/tasks/7' => Http::response(['status' => 'succeeded'], 200),
            '*/indexes/products/documents*' => Http::response(['total' => 0, 'results' => []], 200),
            '*/tasks*' => Http::response(['results' => []], 200),
        ]);

        $this->artisan('search:repair-embeddings')
            ->expectsOutputToContain('Embedder настроен')
            ->assertSuccessful();
    }
}
