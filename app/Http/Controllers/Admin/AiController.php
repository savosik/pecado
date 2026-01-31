<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenAI\Client;

class AiController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:1000',
            'context' => 'nullable|string|max:5000', // Контекст (например, название товара, характеристики)
        ]);

        $apiKey = env('OPENROUTER_API_KEY');

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
            
            if ($mode === 'rewrite') {
                $systemPrompt = "Ты — профессиональный редактор и копирайтер.
                Твоя задача — переписать (рерайт) предоставленный текст, улучшив его читаемость и стиль, сохраняя смысл.
                Используй HTML форматирование (p, ul, li, strong), если это уместно в исходном тексте.
                Верни результат как чистый HTML без Markdown-разметки (```html).
                Не делай двойные переносы строк между абзацами, используй тег <p> для разделения.";
            } else {
                $systemPrompt = "Ты — профессиональный копирайтер для интернет-магазина одежды и аксессуаров. 
                Твоя задача — писать продающие, грамотные и привлекательные описания товаров на русском языке.
                Используй HTML форматирование (p, ul, li, strong), если это уместно.
                Верни результат как чистый HTML без Markdown-разметки (```html).
                Не делай двойные переносы строк между абзацами, используй тег <p> для разделения.";
            }

            if ($request->context) {
                $systemPrompt .= "\n\nКонтекст товара:\n" . $request->context;
            }

            $response = $client->chat()->create([
                'model' => 'openai/gpt-4o-mini', // Используем модель через OpenRouter
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $request->prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000,
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
}
