<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenAI\Client;

class AiController extends Controller
{
    /**
     * AI генерация / редактирование контента.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:2000',
            'context' => 'nullable|string|max:5000',
            'current_content' => 'nullable|string|max:100000',
            'selected_text' => 'nullable|string|max:50000',
            'mode' => 'nullable|string|in:generation,rewrite,edit,edit_selection',
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

            if ($mode === 'edit_selection') {
                // Режим: редактирование выделенного фрагмента
                $messages[] = [
                    'role' => 'user',
                    'content' => "Выделенный фрагмент HTML, который нужно изменить:\n\n" . $request->selected_text,
                ];
                $messages[] = [
                    'role' => 'assistant',
                    'content' => 'Вижу выделенный фрагмент. Какие изменения внести?',
                ];
                $messages[] = [
                    'role' => 'user',
                    'content' => $request->prompt,
                ];
            } elseif ($mode === 'edit' && $request->current_content) {
                // Режим: редактирование всего документа
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
            } elseif ($mode === 'generation' && $request->current_content) {
                // Генерация с учётом существующего контента
                $messages[] = [
                    'role' => 'user',
                    'content' => "В документе уже есть контент:\n\n" . $request->current_content .
                        "\n\n---\n\nЗадание: " . $request->prompt .
                        "\n\nВАЖНО: верни ВЕСЬ документ полностью (и старый контент, и новый). Не сокращай и не обрезай существующий текст.",
                ];
            } else {
                $messages[] = [
                    'role' => 'user',
                    'content' => $request->prompt,
                ];
            }

            $response = $client->chat()->create([
                'model' => 'google/gemini-3.1-pro-preview',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 16000,
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
        $stylingRules = "
Если пользователь ЯВНО просит изменить цвет, выделить, покрасить текст — используй:
- Цвет текста: <span style=\"color: #e53e3e\">красный</span>, <span style=\"color: #3182ce\">синий</span>, <span style=\"color: #38a169\">зелёный</span>
- Выделение маркером: <mark style=\"background: #fef08a\">текст</mark>
- Подчёркивание: <u>текст</u>
НО: НЕ добавляй цвета, маркеры и подчёркивания по своей инициативе. Только если пользователь ЯВНО просит.";

        $prompt = match ($mode) {
            'edit_selection' => "Ты — профессиональный редактор контента.
Тебе передаётся ВЫДЕЛЕННЫЙ ФРАГМЕНТ HTML-документа и инструкция по его изменению.
Верни ТОЛЬКО изменённый фрагмент (не весь документ, а только ту часть что была выделена).
{$stylingRules}
ВАЖНО:
- Возвращай ТОЛЬКО чистый HTML, без Markdown, без ```html обёрток.
- Не добавляй <!DOCTYPE>, <html>, <body> и другие обёртки.",

            'edit' => "Ты — профессиональный редактор контента.
Тебе передаётся HTML-документ и инструкция по его изменению.
Твоя задача — внести запрошенные изменения и вернуть ВЕСЬ документ полностью с изменениями.
{$stylingRules}
ВАЖНО:
- Возвращай ТОЛЬКО чистый HTML, без Markdown, без ```html обёрток.
- Сохраняй ВСЮ существующую структуру и контент, если не просят изменить.
- НЕ СОКРАЩАЙ и НЕ ОБРЕЗАЙ текст — верни документ ПОЛНОСТЬЮ.
- НЕ добавляй цвета, underline, mark, highlight и другие визуальные стили, если пользователь НЕ просил.
- Не добавляй <!DOCTYPE>, <html>, <body> и другие обёртки.",

            'rewrite' => "Ты — профессиональный редактор и копирайтер.
Перепиши предоставленный текст, улучшив читаемость и стиль, сохраняя смысл и длину.
НЕ добавляй цветное выделение, маркеры и подчёркивания. Пиши чистый текст.
Используй только: <p>, <h2>, <h3>, <ul>, <ol>, <li>, <strong>, <em>, <blockquote>.
Верни результат как чистый HTML без Markdown-разметки.",

            default => "Ты — профессиональный копирайтер для интернет-магазина одежды и аксессуаров.
Пиши продающие, грамотные и привлекательные описания на русском языке.
ВАЖНО:
- Возвращай ТОЛЬКО чистый HTML, без Markdown, без ```html обёрток.
- Используй ТОЛЬКО теги: <p>, <h2>, <h3>, <h4>, <ul>, <ol>, <li>, <strong>, <em>, <blockquote>, <hr>, <table>, <tr>, <th>, <td>.
- НЕ используй цветной текст, маркеры, подчёркивания, inline-стили. Пиши ЧИСТО.
- Создавай ДЛИННЫЙ, ПОДРОБНЫЙ контент (минимум 5-7 абзацев с подзаголовками).
- НЕ обрезай текст — пиши полностью до логического конца.
- Не добавляй <!DOCTYPE>, <html>, <body> и другие обёртки.",
        };

        if ($context) {
            $prompt .= "\n\nКонтекст:\n" . $context;
        }

        return $prompt;
    }
}
