<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер AI-генерации структурированных блоков для Editor.js.
 *
 * POST /admin/api/ai-generate-block
 *
 * Использует OpenRouter (Gemini 2.5 Flash) для генерации контента
 * в формате конкретного блока Editor.js.
 */
class AiBlockController extends Controller
{
    /**
     * Допустимые типы блоков для генерации.
     */
    private const ALLOWED_BLOCK_TYPES = [
        'paragraph', 'header', 'list', 'faq', 'table', 'steps', 'tabs',
        'iconFeature', 'stats', 'timeline', 'comparison', 'reviews',
        'callToAction', 'alertBanner', 'pullQuote', 'opinionBox',
        'pricingTable', 'imageText', 'warning', 'quote',
    ];

    /**
     * Сгенерировать блок контента.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:3000',
            'block_type' => 'required|string|in:' . implode(',', self::ALLOWED_BLOCK_TYPES),
        ]);

        $apiKey = config('normalizer.api_key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'OpenRouter API ключ не настроен. Добавьте OPENROUTER_API_KEY в .env',
            ], 500);
        }

        try {
            $client = \OpenAI::factory()
                ->withBaseUri('https://openrouter.ai/api/v1')
                ->withHttpHeader('HTTP-Referer', config('app.url'))
                ->withHttpHeader('X-Title', config('app.name'))
                ->withApiKey($apiKey)
                ->make();

            $blockType = $request->input('block_type');
            $prompt = $request->input('prompt');

            $systemPrompt = $this->buildSystemPrompt($blockType);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ];

            $response = $client->chat()->create([
                'model' => 'google/gemini-2.5-flash-preview',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 8000,
            ]);

            // Парсинг ответа
            $content = null;

            if (isset($response->choices[0]->message->content)) {
                $content = $response->choices[0]->message->content;
            } elseif (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                $content = $response['choices'][0]['message']['content'];
            } elseif (method_exists($response, 'toArray')) {
                $arr = $response->toArray();
                $content = $arr['choices'][0]['message']['content'] ?? null;
            }

            if (!$content) {
                Log::error('AI Block: не удалось получить ответ', [
                    'type' => get_class($response),
                ]);
                throw new \Exception('Не удалось получить ответ от AI модели.');
            }

            // Извлекаем JSON из ответа (может быть обёрнут в ```json ... ```)
            $json = $this->extractJson($content);

            if (!$json) {
                Log::warning('AI Block: не удалось распарсить JSON', [
                    'raw' => $content,
                ]);
                throw new \Exception('ИИ вернул некорректный формат. Попробуйте ещё раз.');
            }

            // Валидация структуры
            if (!isset($json['type']) || !isset($json['data'])) {
                throw new \Exception('ИИ вернул блок без обязательных полей type/data.');
            }

            return response()->json([
                'block' => [
                    'type' => $json['type'],
                    'data' => $json['data'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('AI Block generation error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Ошибка генерации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Извлечь JSON-объект из текстового ответа ИИ.
     */
    private function extractJson(string $raw): ?array
    {
        // Попытка 1: весь ответ — валидный JSON
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Попытка 2: JSON обёрнут в ```json ... ```
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $raw, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Попытка 3: найти первый { ... } блок
        if (preg_match('/\{[\s\S]*\}/m', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Построить системный промт с описанием целевого блока.
     */
    private function buildSystemPrompt(string $blockType): string
    {
        $schemas = $this->getBlockSchemas();
        $targetSchema = $schemas[$blockType] ?? null;

        $schemaJson = $targetSchema
            ? json_encode($targetSchema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : '(схема неизвестна)';

        return <<<PROMPT
Ты — AI-ассистент для CMS Pecado (B2B интернет-магазин интим-товаров).
Твоя задача — по промту пользователя сгенерировать контентный блок для Editor.js.

ЦЕЛЕВОЙ ТИП БЛОКА: {$blockType}

СХЕМА БЛОКА (JSON):
{$schemaJson}

ПРАВИЛА:
1. Верни ТОЛЬКО валидный JSON-объект с полями "type" и "data".
2. Поле "type" должно быть строго "{$blockType}".
3. Поле "data" должно точно соответствовать схеме блока — все обязательные поля должны быть заполнены.
4. Контент пиши на русском языке.
5. Контент должен быть грамотным, информативным и профессиональным.
6. Не оборачивай JSON в ```json ... ``` — возвращай чистый JSON.
7. Не добавляй комментарии или пояснения — только JSON.
8. Для текстовых полей можно использовать inline HTML: <b>, <i>, <a href>, <mark>.
9. Контекст: B2B-дистрибьютор интим-товаров для магазинов. Профессиональный, деловой тон.

Пример ответа:
{"type": "{$blockType}", "data": { ... }}
PROMPT;
    }

    /**
     * Получить схемы всех блоков.
     */
    private function getBlockSchemas(): array
    {
        return [
            'paragraph' => [
                'description' => 'Абзац текста',
                'example' => ['type' => 'paragraph', 'data' => ['text' => 'Текст абзаца']],
            ],
            'header' => [
                'description' => 'Заголовок уровня 2, 3 или 4',
                'example' => ['type' => 'header', 'data' => ['text' => 'Заголовок', 'level' => 2]],
            ],
            'list' => [
                'description' => 'Маркированный или нумерованный список',
                'data_schema' => ['style' => 'unordered|ordered', 'items' => [['content' => 'string', 'items' => []]]],
                'example' => ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [['content' => 'Пункт 1', 'items' => []], ['content' => 'Пункт 2', 'items' => []]]]],
            ],
            'faq' => [
                'description' => 'Блок вопрос-ответ (аккордеон)',
                'data_schema' => ['items' => [['question' => 'string', 'answer' => 'string']]],
                'example' => ['type' => 'faq', 'data' => ['items' => [['question' => 'Вопрос?', 'answer' => 'Ответ.']]]],
            ],
            'table' => [
                'description' => 'Таблица данных',
                'data_schema' => ['withHeadings' => 'boolean', 'content' => [['string', 'string']]],
                'example' => ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Колонка 1', 'Колонка 2'], ['Значение', 'Значение']]]],
            ],
            'steps' => [
                'description' => 'Пронумерованные шаги',
                'data_schema' => ['steps' => [['title' => 'string', 'text' => 'string']]],
                'example' => ['type' => 'steps', 'data' => ['steps' => [['title' => 'Шаг 1', 'text' => 'Описание']]]],
            ],
            'tabs' => [
                'description' => 'Вкладки с контентом',
                'data_schema' => ['tabs' => [['title' => 'string', 'content' => 'string (HTML)']]],
                'example' => ['type' => 'tabs', 'data' => ['tabs' => [['title' => 'Вкладка 1', 'content' => 'Содержимое']]]],
            ],
            'iconFeature' => [
                'description' => 'Сетка карточек с иконками (эмодзи), заголовками и описаниями',
                'data_schema' => ['columns' => '2|3|4', 'items' => [['icon' => 'emoji', 'title' => 'string', 'text' => 'string']]],
                'example' => ['type' => 'iconFeature', 'data' => ['columns' => '3', 'items' => [['icon' => '🚀', 'title' => 'Заголовок', 'text' => 'Описание']]]],
            ],
            'stats' => [
                'description' => 'Блок числовой статистики',
                'data_schema' => ['items' => [['value' => 'string', 'label' => 'string']]],
                'example' => ['type' => 'stats', 'data' => ['items' => [['value' => '100+', 'label' => 'Клиентов']]]],
            ],
            'timeline' => [
                'description' => 'Хронология событий',
                'data_schema' => ['events' => [['date' => 'string', 'title' => 'string', 'text' => 'string']]],
                'example' => ['type' => 'timeline', 'data' => ['events' => [['date' => '2024', 'title' => 'Событие', 'text' => 'Описание']]]],
            ],
            'comparison' => [
                'description' => 'Таблица сравнения двух вариантов',
                'data_schema' => [
                    'col1Title' => 'string',
                    'col2Title' => 'string',
                    'rows' => [['feature' => 'string', 'col1Text' => 'string', 'col1Icon' => 'none|check|cross|text', 'col2Text' => 'string', 'col2Icon' => 'none|check|cross|text']],
                ],
                'example' => ['type' => 'comparison', 'data' => ['col1Title' => 'Другие', 'col2Title' => 'Pecado', 'rows' => [['feature' => 'Доставка', 'col1Text' => 'Нет', 'col1Icon' => 'cross', 'col2Text' => 'Да', 'col2Icon' => 'check']]]],
            ],
            'reviews' => [
                'description' => 'Блок отзывов с рейтингом',
                'data_schema' => ['items' => [['author' => 'string', 'text' => 'string', 'rating' => '1-5']]],
                'example' => ['type' => 'reviews', 'data' => ['items' => [['author' => 'Клиент', 'text' => 'Отзыв', 'rating' => 5]]]],
            ],
            'callToAction' => [
                'description' => 'CTA-блок с заголовком, текстом и кнопкой',
                'data_schema' => ['title' => 'string', 'text' => 'string', 'buttonText' => 'string', 'buttonUrl' => 'string', 'style' => 'primary|secondary|gradient'],
                'example' => ['type' => 'callToAction', 'data' => ['title' => 'Заголовок', 'text' => 'Описание', 'buttonText' => 'Кнопка', 'buttonUrl' => '/catalog', 'style' => 'primary']],
            ],
            'alertBanner' => [
                'description' => 'Баннер-уведомление',
                'data_schema' => ['text' => 'string', 'style' => 'promo|info|warning', 'btnText' => 'string (пусто = без кнопки)', 'btnUrl' => 'string'],
                'example' => ['type' => 'alertBanner', 'data' => ['text' => 'Текст баннера', 'style' => 'promo', 'btnText' => 'Подробнее', 'btnUrl' => '/promo']],
            ],
            'pullQuote' => [
                'description' => 'Крупная центрированная цитата',
                'data_schema' => ['text' => 'string', 'caption' => 'string'],
                'example' => ['type' => 'pullQuote', 'data' => ['text' => 'Цитата', 'caption' => 'Автор']],
            ],
            'opinionBox' => [
                'description' => 'Мнение эксперта с фото, именем и должностью',
                'data_schema' => ['name' => 'string', 'title' => 'string (должность)', 'photo' => 'string (URL, можно пустая строка)', 'text' => 'string'],
                'example' => ['type' => 'opinionBox', 'data' => ['name' => 'Имя', 'title' => 'Должность', 'photo' => '', 'text' => 'Мнение']],
            ],
            'pricingTable' => [
                'description' => 'Тарифные планы',
                'data_schema' => ['plans' => [['title' => 'string', 'price' => 'string', 'btnText' => 'string', 'btnUrl' => 'string', 'features' => ['string'], 'isPopular' => 'boolean']]],
                'example' => ['type' => 'pricingTable', 'data' => ['plans' => [['title' => 'Базовый', 'price' => '0 ₽', 'btnText' => 'Выбрать', 'btnUrl' => '', 'features' => ['Фича 1'], 'isPopular' => false]]]],
            ],
            'imageText' => [
                'description' => 'Блок фото + текст рядом',
                'data_schema' => ['imageUrl' => 'string (URL, можно пустая строка)', 'imagePosition' => 'left|right', 'title' => 'string', 'text' => 'string'],
                'example' => ['type' => 'imageText', 'data' => ['imageUrl' => '', 'imagePosition' => 'left', 'title' => 'Заголовок', 'text' => 'Описание']],
            ],
            'warning' => [
                'description' => 'Информационный блок с предупреждением',
                'data_schema' => ['title' => 'string', 'message' => 'string'],
                'example' => ['type' => 'warning', 'data' => ['title' => 'Важно', 'message' => 'Текст предупреждения']],
            ],
            'quote' => [
                'description' => 'Цитата с автором',
                'data_schema' => ['text' => 'string', 'caption' => 'string', 'alignment' => 'left|center'],
                'example' => ['type' => 'quote', 'data' => ['text' => 'Текст цитаты', 'caption' => 'Автор', 'alignment' => 'left']],
            ],
        ];
    }
}
