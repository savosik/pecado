<?php

namespace Tests\Feature\Search;

use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UrlRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function fetchPage(string $url): array
    {
        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
    }

    /**
     * Smoke-тест A-3 (PR 5.4): полный набор query-параметров заказов
     * восстанавливается в `filters` props при прямом открытии URL.
     */
    #[Test]
    public function orders_index_restores_full_filter_set_from_url(): void
    {
        $page = $this->fetchPage(
            '/cabinet/orders?search=ПРИВЕТ'
            .'&status%5B%5D='.OrderStatus::READY_FOR_PROVISION->value
            .'&date_from=2026-01-01&date_to=2026-04-29'
            .'&amount_from=100&amount_to=5000'
            .'&items_count_from=2&items_count_to=10'
            .'&sort_by=total_amount&sort_order=asc'
        );

        $filters = $page['props']['filters'];
        $this->assertSame('ПРИВЕТ', $filters['search']);
        $this->assertSame([OrderStatus::READY_FOR_PROVISION->value], $filters['status']);
        $this->assertSame('2026-01-01', $filters['date_from']);
        $this->assertSame('2026-04-29', $filters['date_to']);
        $this->assertSame('100', (string) $filters['amount_from']);
        $this->assertSame('5000', (string) $filters['amount_to']);
        $this->assertSame(2, $filters['items_count_from']);
        $this->assertSame(10, $filters['items_count_to']);
        $this->assertSame('total_amount', $filters['sort_by']);
        $this->assertSame('asc', $filters['sort_order']);
    }
}
