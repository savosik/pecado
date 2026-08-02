<?php

namespace App\Services\Product\RichContent;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use OpenAI\Factory as OpenAIFactory;

class RichContentGenerator
{
    public function __construct(
        private readonly BlockSchemaProvider $schemaProvider,
        private readonly BlockJsonSchemaBuilder $schemaBuilder,
    ) {}

    /**
     * Сгенерировать rich_content для товара и сохранить в БД.
     *
     * @return array{blocks: array<int, array<string, mixed>>}
     *
     * @throws RichContentGenerationException
     */
    public function generate(Product $product): array
    {
        $allowedTypes = $this->allowedTypes();
        $sourceText = $this->extractSourceText($product);
        $minLength = (int) config('rich_content.min_description_length', 80);

        if (mb_strlen($sourceText) < $minLength) {
            throw new RichContentGenerationException(
                'Описание товара слишком короткое для генерации (длина: '.mb_strlen($sourceText).", минимум: {$minLength})."
            );
        }

        $apiKey = config('normalizer.api_key');
        if (! $apiKey) {
            throw new RichContentGenerationException('OPENROUTER_API_KEY не настроен.');
        }

        $client = $this->buildClient($apiKey);

        $systemPrompt = $this->buildSystemPrompt($allowedTypes);
        $userPrompt = $this->buildUserPrompt($product, $sourceText);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $rawJson = null;

        try {
            $rawJson = $this->callModel($client, $messages);
            $payload = $this->parseAndValidate($rawJson, $allowedTypes);
        } catch (RichContentGenerationException $firstError) {
            // Retry имеет смысл только когда LLM ответил, но JSON не прошёл валидацию.
            // На сетевые ошибки/таймауты callModel второй запрос только дублирует фейл
            // и часто получает TLS-обрыв, потому что предыдущее соединение ещё в полёте.
            if ($rawJson === null) {
                throw $firstError;
            }

            $messages[] = ['role' => 'assistant', 'content' => $rawJson];
            $messages[] = [
                'role' => 'user',
                'content' => 'Твой предыдущий ответ не прошёл валидацию: '.$firstError->getMessage()."\n\n".
                    'Верни валидный JSON строго по схеме. Используй только разрешённые типы блоков. '.
                    'Возвращай ТОЛЬКО объект {"blocks": [...]} без markdown-обёртки.',
            ];

            $rawJson = $this->callModel($client, $messages);
            $payload = $this->parseAndValidate($rawJson, $allowedTypes);
        }

        $product->forceFill([
            'rich_content' => $payload,
            'rich_content_generated_at' => now(),
            'rich_content_generation_failed_at' => null,
            'rich_content_generation_attempts' => ($product->rich_content_generation_attempts ?? 0) + 1,
        ])->saveQuietly();

        return $payload;
    }

    /**
     * Зафиксировать неудачную попытку генерации, чтобы сработал cooldown.
     */
    public function recordFailure(Product $product): void
    {
        $product->forceFill([
            'rich_content_generation_failed_at' => now(),
            'rich_content_generation_attempts' => ($product->rich_content_generation_attempts ?? 0) + 1,
        ])->saveQuietly();
    }

    /** @return list<string> */
    private function allowedTypes(): array
    {
        $types = config('rich_content.allowed_block_types', []);

        return array_values(array_filter($types, 'is_string'));
    }

    private function extractSourceText(Product $product): string
    {
        $candidates = [
            $product->description,
            $product->description_html ? strip_tags($product->description_html) : null,
            $product->short_description,
        ];

        foreach ($candidates as $text) {
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return '';
    }

    /** @param list<string> $allowedTypes */
    private function buildSystemPrompt(array $allowedTypes): string
    {
        $context = $this->schemaProvider->promptContext($allowedTypes);
        $allowed = implode(', ', $allowedTypes);

        return <<<PROMPT
Ты — копирайтер-маркетолог интернет-магазина Pecado. Твоя задача — переписать описание товара в виде структурированного маркетингового текста и вернуть его как JSON для редактора Editor.js.

ПРАВИЛА:
1. Возвращай ТОЛЬКО валидный JSON-объект со структурой {"blocks": [...]}. Без markdown-обёртки, без комментариев, без пояснений до или после.
2. Каждый блок имеет вид {"type": "...", "data": {...}} и должен строго соответствовать описанной ниже схеме.
3. Используй ТОЛЬКО следующие типы блоков: {$allowed}.
4. Не используй все блоки сразу. Выбирай те, что подходят по содержанию: 4–10 блоков обычно достаточно. Если в исходном тексте мало данных — лучше меньше блоков, чем выдумывать.
5. ЗАПРЕЩЕНО: придумывать факты, которых нет в исходном описании (характеристики, размеры, числа, состав, гарантии, отзывы, имена). Можно перефразировать и структурировать — но не добавлять новые сущности.
6. Тон: профессиональный, продающий, без переспама эмодзи и восклицаний. Не используй слова «лучший», «уникальный», «революционный» без оснований.
7. Язык: русский. Формат текста — для конечного покупателя B2C/B2B.
8. В text-полях разрешён inline HTML: <b>, <i>, <mark>, <code>. ЗАПРЕЩЕНО использовать <a href> — у тебя нет настоящих URL каталога, любая выдуманная ссылка ведёт в никуда. Также НЕ создавай кнопок и CTA-ссылок ни в каком виде.
9. Начинай ВСЕГДА с блока paragraph. Первый блок НЕ должен быть header, delimiter или alertBanner.
10. ЗАПРЕЩЕНО повторять название товара в первом блоке или в любом header — название уже отображается в шапке карточки. Если нужен заголовок раздела, формулируй его обобщённо («О продукте», «Применение», «Состав»), без названия товара.
11. Используй блок `steps` ТОЛЬКО для последовательной инструкции, где порядок шагов важен и каждый шаг — это действие («Шаг 1. Налейте... Шаг 2. Нанесите...»). НЕ оформляй разные тематические разделы описания (применение, состав, особенности) как `steps` — для них используй `header` (level 3) + `paragraph`.
12. НЕ превращай связное повествование в списки — оставляй абзацами. Списки и `iconFeature` уместны только когда в исходном тексте действительно есть перечисление однородных пунктов (3+ элементов одного типа: преимущества, характеристики, варианты применения).
13. ПРИ НАЛИЧИИ ТАКИХ ПЕРЕЧИСЛЕНИЙ предпочитай `iconFeature` обычному `list` — визуально это выразительнее. Подбирай к каждому пункту осмысленный эмодзи (🌿 для натуральности, 💧 для гидратации, 🔒 для надёжности, ⚡ для быстроты, 📏 для размеров, 🎁 для подарка и т.п.). Каждый элемент `iconFeature` должен иметь короткий заголовок (2–4 слова) и описание (1 предложение из исходного текста). Минимум 2, максимум 6 элементов; columns: «2» если 2–4 пункта, «3» если 5–6.
14. Простой `list` оставляй только для совсем коротких пунктов в одно слово/фразу, где нет смысла придумывать заголовок и описание (например: «Размер S», «Размер M», «Размер L»).
15. НЕ пиши заглавными буквами целые слова или фразы — это воспринимается как крик и считается дурным тоном. CAPS допустим только для общепринятых аббревиатур (USB, BDSM, IPX7, ISO, TPE и т.п.).
16. НЕ упоминай в результате дефекты, повреждения, потёртости, царапины, статус «уценка/ликвидация» и любые другие негативные свойства товара — даже если они явно указаны в исходном описании. Маркетинговый текст должен подсвечивать только преимущества и характеристики, не привлекая внимание к недостаткам.

ДОСТУПНЫЕ БЛОКИ:

{$context}

ФИНАЛЬНЫЙ ФОРМАТ ОТВЕТА:
{"blocks": [ {"type": "...", "data": {...}}, ... ]}
PROMPT;
    }

    private function buildUserPrompt(Product $product, string $sourceText): string
    {
        $name = trim((string) $product->name);
        $short = trim((string) $product->short_description);
        $shortLine = $short !== '' ? "Короткое описание: {$short}\n" : '';

        return <<<PROMPT
Товар: {$name}
{$shortLine}
Исходное описание (markdown/HTML):
---
{$sourceText}
---

Перепиши и структурируй описание этого товара в формате Editor.js JSON по правилам выше.
PROMPT;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function callModel(ClientContract $client, array $messages): string
    {
        try {
            $response = $client->chat()->create([
                'model' => config('rich_content.model'),
                'messages' => $messages,
                'temperature' => (float) config('rich_content.temperature'),
                'max_tokens' => (int) config('rich_content.max_tokens'),
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('RichContent: ошибка обращения к OpenRouter', [
                'error' => $e->getMessage(),
            ]);
            throw new RichContentGenerationException('Ошибка обращения к OpenRouter: '.$e->getMessage(), 0, $e);
        }

        $content = $this->extractContent($response);
        if ($content === null || $content === '') {
            throw new RichContentGenerationException('LLM вернул пустой ответ.');
        }

        return $content;
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>}
     */
    private function parseAndValidate(string $rawJson, array $allowedTypes): array
    {
        $payload = $this->extractJson($rawJson);

        if (! is_array($payload)) {
            throw new RichContentGenerationException('LLM вернул невалидный JSON.');
        }

        if (! isset($payload['blocks']) || ! is_array($payload['blocks'])) {
            throw new RichContentGenerationException('Ответ LLM не содержит массив blocks.');
        }

        $result = $this->schemaBuilder->validate($payload, $allowedTypes);
        if (! $result['valid']) {
            $errors = implode('; ', array_slice($result['errors'], 0, 5));
            throw new RichContentGenerationException("Ответ LLM не прошёл валидацию схемы: {$errors}");
        }

        /** @var array{blocks: array<int, array<string, mixed>>} $payload */
        return $payload;
    }

    private function extractJson(string $raw): ?array
    {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $raw, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/\{[\s\S]*\}/m', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractContent(mixed $response): ?string
    {
        if (is_object($response) && isset($response->choices[0]->message->content)) {
            return (string) $response->choices[0]->message->content;
        }

        if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
            return (string) $response['choices'][0]['message']['content'];
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            $arr = $response->toArray();

            return $arr['choices'][0]['message']['content'] ?? null;
        }

        return null;
    }

    protected function buildClient(string $apiKey): ClientContract
    {
        return (new OpenAIFactory)
            ->withBaseUri('https://openrouter.ai/api/v1')
            ->withHttpHeader('HTTP-Referer', config('app.url'))
            ->withHttpHeader('X-Title', config('app.name'))
            ->withApiKey($apiKey)
            ->withHttpClient(new \GuzzleHttp\Client(
                \App\Support\OpenRouter::guzzleOptions((int) config('rich_content.request_timeout', 60))
            ))
            ->make();
    }
}
