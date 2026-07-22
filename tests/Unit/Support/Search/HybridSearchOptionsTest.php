<?php

namespace Tests\Unit\Support\Search;

use App\Support\Search\HybridSearchOptions;
use Tests\TestCase;

class HybridSearchOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'search.hybrid.enabled' => true,
            'search.hybrid.embedder' => 'openrouter',
            'search.hybrid.semantic_ratio' => 0.3,
            'search.hybrid.fallback_min_results' => 1,
        ]);
    }

    // ─── forProducts ───────────────────────────────────────────────

    public function test_возвращает_null_если_гибрид_выключен(): void
    {
        config(['search.hybrid.enabled' => false]);

        $this->assertNull(HybridSearchOptions::forProducts());
    }

    public function test_возвращает_опции_hybrid_если_включён(): void
    {
        $options = HybridSearchOptions::forProducts();

        $this->assertSame('openrouter', $options['hybrid']['embedder']);
        $this->assertSame(0.3, $options['hybrid']['semanticRatio']);
    }

    // ─── shouldFallback ────────────────────────────────────────────

    public function test_нет_fallback_если_гибрид_выключен(): void
    {
        config(['search.hybrid.enabled' => false]);

        $this->assertFalse(HybridSearchOptions::shouldFallback(0));
    }

    public function test_fallback_когда_keyword_вернул_меньше_порога(): void
    {
        config(['search.hybrid.fallback_min_results' => 1]);

        $this->assertTrue(HybridSearchOptions::shouldFallback(0));
    }

    public function test_нет_fallback_когда_keyword_дал_результаты(): void
    {
        config(['search.hybrid.fallback_min_results' => 1]);

        $this->assertFalse(HybridSearchOptions::shouldFallback(1));
        $this->assertFalse(HybridSearchOptions::shouldFallback(42));
    }

    public function test_порог_fallback_настраивается(): void
    {
        config(['search.hybrid.fallback_min_results' => 3]);

        $this->assertTrue(HybridSearchOptions::shouldFallback(2));
        $this->assertFalse(HybridSearchOptions::shouldFallback(3));
    }
}
