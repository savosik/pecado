<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenAI\Client;

class AiController extends Controller
{
    /**
     * Генерация нового контента или рерайт.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:1000',
            'context' => 'nullable|string|max:5000',
            'current_content' => 'nullable|string|max:50000',
            'mode' => 'nullable|string|in:generation,rewrite,edit',
        ]);

        $apiKey = config('normalizer.api_key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'OpenRouter API key is missing. Please configure OPENROUTER_API_KEY in .env',
            ], 500);
        }

        try {
            $client = \OpenAI::factory()
                ->withBaseUri('https://openrouter.ai/api/v1')
                ->withHttpHeader('HTTP-Referer', config('app.url'))
                ->withHttpHeader('X-Title', config('app.name'))
                ->withApiKey($apiKey)
                ->make();

            $mode = $request->input('mode', 'generation');
            $systemPrompt = $this->buildSystemPrompt($mode, $request->context);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            // Для режима edit передаём текущий контент как контекст
            if ($mode === 'edit' && $request->current_content) {
                $messages[] = [
                    'role' => 'user',
                    'content' => "Текущий HTML-документ:\n\n" . $request->current_content,
                ];
                $messages[] = [
                    'role' => 'assistant',
                    'content' => 'Понял. Я вижу текущий документ. Какие изменения нужно внести?',
                ];
                $messages[] = [
                    'role' => 'user',
                    'content' => $request->prompt,
                ];
            } else {
                $messages[] = [
                    'role' => 'user',
                    'content' => $request->prompt,
                ];
            }

            $response = $client->chat()->create([
                'model' => 'openai/gpt-4o-mini',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 4000,
            ]);

            return response()->json([
                'content' => $response->choices[0]->message->content,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при генерации текста: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Построить system prompt в зависимости от режима.
     */
    private function buildSystemPrompt(string $mode, ?string $context): string
    {
        $prompt = match ($mode) {
            'edit' => "Ты — профессиональный редактор контента.
Тебе передаётся HTML-документ и инструкция по его изменению.
Твоя задача — внести запрошенные изменения и вернуть ВЕСЬ документ полностью с изменениями.
ВАЖНО:
- Возвращай ТОЛЬКО чистый HTML, без Markdown, без ```html обёрток.
- Сохраняй существующую структуру и форматирование, если не просят изменить.
- Используй HTML теги: p, h2, h3, h4, ul, ol, li, strong, em, blockquote, table, tr, th, td.
- Если просят добавить контент — добавляй в логичное место документа.
- Для выделения используй inline-стили: style=\"color: ...\", style=\"background: ...\".
- Не добавляй HTML-обёртки вроде <html>, <body>, <!DOCTYPE>.",

            'rewrite' => "Ты — профессиональный редактор и копирайтер.
Твоя задача — переписать (рерайт) предоставленный текст, улучшив его читаемость и стиль, сохраняя смысл.
Используй HTML форматирование (p, ul, li, strong), если это уместно в исходном тексте.
Верни результат как чистый HTML без Markdown-разметки (```html).
Не делай двойные переносы строк между абзацами, используй тег <p> для разделения.",

            default => "Ты — профессиональный копирайтер для интернет-магазина одежды и аксессуаров.
Твоя задача — писать продающие, грамотные и привлекательные описания товаров на русском языке.
Используй HTML форматирование (p, h2, h3, ul, ol, li, strong, em, blockquote), чтобы текст был хорошо структурирован.
Верни результат как чистый HTML без Markdown-разметки (```html).
Не делай двойные переносы строк между абзацами, используй тег <p> для разделения.
Создавай богатый, интересный контент с подзаголовками, списками и акцентами.",
        };

        if ($context) {
            $prompt .= "\n\nКонтекст:\n" . $context;
        }

        return $prompt;
    }
}
