<?php

namespace App\Mcp\Tools\Client;

use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Что умеет клиентский API и что из этого открыто этому клиенту.
 *
 * Каталог вместо десятков отдельных инструментов: операций за семьдесят, и
 * выложенные по одному они превратили бы список инструментов в полотно.
 */
#[IsReadOnly]
class ClientCatalog extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-catalog';

    protected string $description = 'Список операций кабинета клиента с назначением и доступностью. '
        .'Начинать с него: allowed=false означает, что раздел выключен для клиента (denied_reason объясняет), '
        .'и вызывать такую операцию бесполезно. Здесь же — юрлица клиента и состояние разделов.';

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->description('Раздел: '.implode(', ', array_keys($this->registry->sections())).'. Пусто — все.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить клиента по токену.');
        }

        $section = trim((string) $request->get('section', ''));
        $features = [];

        foreach (FeatureGate::cases() as $gate) {
            if ($gate !== FeatureGate::NONE) {
                $features[$gate->value] = $gate->allows($actor);
            }
        }

        return $this->payload([
            'actor' => $actor->name,
            'companies' => $actor->companies()->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'inn' => $c->tax_id, 'is_default' => (bool) $c->is_default])
                ->values()->all(),
            'features' => $features,
            'sections' => $this->registry->sections(),
            'operations' => $this->registry->catalog($actor, $section === '' ? null : $section),
            'hint' => 'Схема аргументов операции — в client-describe, выполнение — в client-call. '
                .'Пишущие операции с idempotent=true принимают idempotency_key; где idempotency_required=true — он обязателен.',
        ]);
    }
}
