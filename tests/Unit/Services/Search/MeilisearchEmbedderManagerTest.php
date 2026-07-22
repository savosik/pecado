<?php

namespace Tests\Unit\Services\Search;

use App\Services\Search\MeilisearchEmbedderManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeilisearchEmbedderManagerTest extends TestCase
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

    // ─── documentLacksVector (чистая функция) ──────────────────────

    public function test_отсутствие_ключа_vectors_считается_без_вектора(): void
    {
        $this->assertTrue(
            MeilisearchEmbedderManager::documentLacksVector(['id' => 1], 'openrouter')
        );
    }

    public function test_другой_embedder_считается_без_вектора(): void
    {
        $doc = ['id' => 1, '_vectors' => ['other' => ['embeddings' => [[0.1, 0.2]]]]];

        $this->assertTrue(
            MeilisearchEmbedderManager::documentLacksVector($doc, 'openrouter')
        );
    }

    public function test_пустой_embeddings_считается_без_вектора(): void
    {
        $doc = ['id' => 1, '_vectors' => ['openrouter' => ['embeddings' => [], 'regenerate' => true]]];

        $this->assertTrue(
            MeilisearchEmbedderManager::documentLacksVector($doc, 'openrouter')
        );
    }

    public function test_заполненный_embeddings_считается_с_вектором(): void
    {
        $doc = ['id' => 1, '_vectors' => ['openrouter' => ['embeddings' => [[0.1, 0.2, 0.3]], 'regenerate' => true]]];

        $this->assertFalse(
            MeilisearchEmbedderManager::documentLacksVector($doc, 'openrouter')
        );
    }

    public function test_сырой_непустой_массив_считается_с_вектором(): void
    {
        $doc = ['id' => 1, '_vectors' => ['openrouter' => [[0.1, 0.2, 0.3]]]];

        $this->assertFalse(
            MeilisearchEmbedderManager::documentLacksVector($doc, 'openrouter')
        );
    }

    // ─── documentIdsMissingVectors (Http::fake) ────────────────────

    public function test_собирает_id_документов_без_вектора(): void
    {
        Http::fake([
            '*/indexes/products/documents*' => Http::response([
                'total' => 3,
                'results' => [
                    ['id' => 10, '_vectors' => ['openrouter' => ['embeddings' => [[0.1]], 'regenerate' => true]]],
                    ['id' => 20], // без вектора
                    ['id' => 30, '_vectors' => ['openrouter' => ['embeddings' => [], 'regenerate' => true]]], // пустой
                ],
            ], 200),
        ]);

        $manager = new MeilisearchEmbedderManager;
        $result = $manager->documentIdsMissingVectors();

        $this->assertSame([20, 30], $result['missing']);
        $this->assertSame(3, $result['total_scanned']);
        $this->assertSame(3, $result['index_total']);
    }

    public function test_limit_ограничивает_скан(): void
    {
        Http::fake([
            '*/indexes/products/documents*' => Http::response([
                'total' => 100,
                'results' => [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ],
            ], 200),
        ]);

        $manager = new MeilisearchEmbedderManager;
        $result = $manager->documentIdsMissingVectors(2);

        $this->assertSame(2, $result['total_scanned']);
        $this->assertSame([1, 2], $result['missing']);
    }

    // ─── getEmbedder ───────────────────────────────────────────────

    public function test_get_embedder_возвращает_настройки_нашего_embedder(): void
    {
        Http::fake([
            '*/indexes/products/settings/embedders' => Http::response([
                'openrouter' => ['source' => 'rest', 'dimensions' => 3072],
            ], 200),
        ]);

        $manager = new MeilisearchEmbedderManager;
        $embedder = $manager->getEmbedder();

        $this->assertIsArray($embedder);
        $this->assertSame(3072, $embedder['dimensions']);
    }

    public function test_get_embedder_возвращает_null_если_не_настроен(): void
    {
        Http::fake([
            '*/indexes/products/settings/embedders' => Http::response([], 200),
        ]);

        $manager = new MeilisearchEmbedderManager;

        $this->assertNull($manager->getEmbedder());
    }

    public function test_payload_содержит_document_template_и_dimensions_из_конфига(): void
    {
        $manager = new MeilisearchEmbedderManager;
        $payload = $manager->embedderPayload();

        $this->assertArrayHasKey('openrouter', $payload);
        $this->assertSame('rest', $payload['openrouter']['source']);
        $this->assertSame(3072, $payload['openrouter']['dimensions']);
        $this->assertSame(MeilisearchEmbedderManager::DOCUMENT_TEMPLATE, $payload['openrouter']['documentTemplate']);
    }
}
