<?php

namespace Tests\Feature\Assistant;

use App\Models\ApiToken;
use App\Services\Assistant\SystemPrompt;
use App\Services\Client\Api\OperationRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ответы MCP для чата-помощника ужаты: компактный JSON, без uuid/code и null,
 * страницы по 25; для обычных агентов — как было. Список операций в промпте
 * заменяет вызов client-catalog.
 */
class AssistantPayloadTest extends AssistantTestCase
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    private function tool(ApiToken $token, string $name, array $arguments = []): string
    {
        $response = $this->postJson('/mcp/client', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.$token->token, 'Accept' => 'application/json, text/event-stream']);
        $response->assertOk();

        return (string) $response->json('result.content.0.text');
    }

    #[Test]
    public function slim_убирает_служебные_ключи_и_null_сохраняя_списки(): void
    {
        $data = ['data' => [
            ['uuid' => 'b3d7', 'code' => 'УТ-1', 'sku' => 'LE-13', 'barcode' => null, 'name' => 'Товар', 'slug' => 'tovar', 'url' => 'https://pecado.ru/products/tovar', 'currency_code' => 'RUB', 'price' => 1200, 'nested' => ['erp_id' => 'x', 'qty' => 2, 'empty' => null]],
        ], 'meta' => ['has_more' => false, 'next_cursor' => null]];

        $slim = \App\Support\Client\AssistantPayload::slim($data);

        $this->assertSame(['sku' => 'LE-13', 'name' => 'Товар', 'slug' => 'tovar', 'price' => 1200, 'nested' => ['qty' => 2]], $slim['data'][0]);
        $this->assertSame(['has_more' => false], $slim['meta']);
        $this->assertTrue(array_is_list($slim['data']));
    }

    #[Test]
    public function помощнику_компактный_json_и_страница_25_личному_токену_как_было(): void
    {
        $personal = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Личный', 'is_active' => true]);
        $pretty = $this->tool($personal, 'client-promotions');
        $this->assertStringContainsString("\n", $pretty, 'обычному агенту — с отступами');
        $this->assertSame(100, json_decode($pretty, true)['meta']['per_page']);

        $compact = $this->tool($this->assistantToken(), 'client-promotions');
        $this->assertStringNotContainsString("\n", $compact, 'помощнику — без отступов');
        $decoded = json_decode($compact, true);
        $this->assertSame(25, $decoded['meta']['per_page'], 'ярлык просил 100, помощнику отдано 25');
        $this->assertArrayNotHasKey('next_cursor', $decoded['meta'], 'null убран');
    }

    #[Test]
    public function список_операций_в_промпте_покрывает_реестр_и_отмечает_закрытые(): void
    {
        $prompt = app(SystemPrompt::class);
        $block = $prompt->operationsBlock();

        foreach (app(OperationRegistry::class)->all() as $operation) {
            $this->assertMatchesRegularExpression('/^- '.preg_quote($operation->id, '/').'( \\(|( —))/mu', $block, "операция {$operation->id} есть в промпте");
        }

        $this->assertStringContainsString('### ', $block);
        $this->assertStringContainsString('- catalog.search (q*, page, per_page', $block, 'аргументы подписаны, обязательные со звёздочкой');
        $this->assertStringContainsString('/products/{slug}', $block);
        $this->assertStringContainsString('[раздел «документы и акты сверки»]', $block);
        $this->assertSame($block, $prompt->operationsBlock(), 'блок детерминирован — иначе кеш промпта не сходится');
        $this->assertLessThan(30000, mb_strlen($block), 'список операций с аргументами — около 8 тыс. токенов в кешируемом блоке');
    }
}
