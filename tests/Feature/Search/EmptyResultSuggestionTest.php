<?php

namespace Tests\Feature\Search;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmptyResultSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function fetchOrdersPage(string $url): array
    {
        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
    }

    private function suggestion(string $url): mixed
    {
        return $this->fetchOrdersPage($url)['props']['suggestion'] ?? null;
    }

    // ---------- Off-flag ----------

    #[Test]
    public function suggestion_is_null_when_flag_disabled_even_with_empty_result(): void
    {
        // Запрос даёт пустую выдачу (заказов нет), но флаг выключен.
        $this->assertNull($this->suggestion('/cabinet/orders?search=NONEXISTENT-ORDER-XYZ'));
    }

    // ---------- On-flag ----------

    #[Test]
    public function suggestion_is_null_when_no_filters_or_search(): void
    {
        config(['search-cabinet.suggestions' => true]);
        // Пустая выдача (заказов нет вообще) и пустые фильтры → нет смысла
        // подсказывать «уберите X», просто пользователь без заказов.
        $this->assertNull($this->suggestion('/cabinet/orders'));
    }

    #[Test]
    public function suggestion_includes_search_advice(): void
    {
        config(['search-cabinet.suggestions' => true]);

        $text = $this->suggestion('/cabinet/orders?search=NONEXISTENT-XYZ');
        $this->assertIsString($text);
        $this->assertStringContainsString('NONEXISTENT-XYZ', $text);
        $this->assertStringContainsString('Проверьте написание', $text);
    }

    #[Test]
    public function suggestion_includes_filters_advice(): void
    {
        config(['search-cabinet.suggestions' => true]);

        $text = $this->suggestion('/cabinet/orders?status%5B%5D='.OrderStatus::READY_FOR_PROVISION->value);
        $this->assertIsString($text);
        $this->assertStringContainsString('Попробуйте сбросить', $text);
        $this->assertStringContainsString('Статус', $text);
    }

    #[Test]
    public function suggestion_includes_both_when_search_and_filters(): void
    {
        config(['search-cabinet.suggestions' => true]);

        $text = $this->suggestion('/cabinet/orders?search=NONEXISTENT&status%5B%5D='.OrderStatus::READY_FOR_PROVISION->value);
        $this->assertIsString($text);
        $this->assertStringContainsString('Проверьте написание', $text);
        $this->assertStringContainsString('Попробуйте сбросить', $text);
    }

    #[Test]
    public function suggestion_is_null_when_results_present(): void
    {
        config(['search-cabinet.suggestions' => true]);

        Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-WITH-SEARCH-MATCH',
        ]);

        // Поиск найдёт заказ → suggestion не нужен.
        $this->assertNull($this->suggestion('/cabinet/orders?search=WITH-SEARCH-MATCH'));
    }
}
