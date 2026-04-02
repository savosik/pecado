<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DataNormalizerService
{
    /**
     * Нормализовать данные пользователя (name + телефон + email) через AI.
     * name — единое поле: ФИО физлица или название организации.
     */
    public function normalizeUser(
        ?string $name,
        ?string $phone = null,
        ?string $email = null,
    ): ?array {
        $systemPrompt = <<<'PROMPT'
Ты парсер данных контрагентов из 1С. Тебе приходит поле name — это либо ФИО человека, либо название организации.

ПРАВИЛА:
1. Определи тип: "person" (физлицо) или "organization" (ООО, ИП, ЗАО, ОАО, ТОО, АО, Отель и т.п.)
2. Для "organization": верни полное название как оно должно быть (напр. "ООО Яндекс.Маркет"), убери дубли (напр. "ООО ООО Рога" → "ООО Рога")
3. Для "person": нормализуй ФИО (убери лишнее — город, номера, должности). Если есть город в имени → city
4. Телефон: первый номер → формат +7XXXXXXXXXX (ровно 12 символов). 8 в начале = +7. Остальные → extra_info
5. Невалидный email (нет @, число) → email=null
6. Все лишние данные собери в extra_info через "; "

Отвечай ТОЛЬКО JSON без markdown:
{
  "type": "person" | "organization",
  "name": "нормализованное ФИО или полное название организации",
  "city": "string | null",
  "primary_phone": "+7XXXXXXXXXX | null",
  "email": "string | null",
  "extra_info": "string | null"
}
PROMPT;

        $userMessage = "name: \"{$name}\"";

        if ($phone) {
            $userMessage .= "\nphone: \"{$phone}\"";
        }

        if ($email) {
            $userMessage .= "\nemail: \"{$email}\"";
        }

        return $this->callAi($systemPrompt, $userMessage);
    }

    /**
     * Нормализовать данные компании (телефон + email) через AI.
     */
    public function normalizeCompany(
        ?string $phone = null,
        ?string $email = null,
    ): ?array {
        $systemPrompt = <<<'PROMPT'
Ты парсер контактных данных компаний из 1С.

ПРАВИЛА:
1. Телефон: первый номер → +7XXXXXXXXXX (ровно 12 символов). 8 в начале = +7. Остальные → extra_info
2. Невалидный email → null
3. Чистые данные — верни как есть

Отвечай ТОЛЬКО JSON:
{
  "primary_phone": "+7XXXXXXXXXX | null",
  "email": "string | null",
  "extra_info": "string | null"
}
PROMPT;

        $parts = [];
        if ($phone) {
            $parts[] = "phone: \"{$phone}\"";
        }
        if ($email) {
            $parts[] = "email: \"{$email}\"";
        }

        if (empty($parts)) {
            return null;
        }

        return $this->callAi($systemPrompt, implode("\n", $parts));
    }

    /**
     * Нормализовать расчётный счёт — без AI, просто убрать пробелы.
     */
    public function normalizeAccountNumber(string $accountNumber): string
    {
        return preg_replace('/\s+/', '', $accountNumber);
    }

    /**
     * Валидация телефона: +7 + ровно 10 цифр.
     * Если невалидный — возвращает null.
     */
    public static function validatePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        // Исправить +78... → +7...
        if (preg_match('/^\+78(\d{10})$/', $phone, $m)) {
            $phone = '+7' . $m[1];
        }

        // Должно быть ровно +7XXXXXXXXXX
        if (preg_match('/^\+7\d{10}$/', $phone)) {
            return $phone;
        }

        return null; // невалидный — игнорируем
    }

    /**
     * Вызвать OpenRouter API.
     */
    private function callAi(string $systemPrompt, string $userMessage): ?array
    {
        if (! config('normalizer.enabled', true)) {
            return null;
        }

        $apiKey = config('normalizer.api_key');

        if (! $apiKey) {
            Log::warning('DataNormalizerService: OPENROUTER_API_KEY не задан');

            return null;
        }

        try {
            $client = \OpenAI::factory()
                ->withBaseUri('https://openrouter.ai/api/v1')
                ->withHttpHeader('HTTP-Referer', config('app.url'))
                ->withHttpHeader('X-Title', config('app.name'))
                ->withApiKey($apiKey)
                ->withHttpClient(new \GuzzleHttp\Client([
                    'timeout' => config('normalizer.timeout', 30),
                ]))
                ->make();

            $response = $client->chat()->create([
                'model'       => config('normalizer.model', 'openai/gpt-4o-mini'),
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'temperature' => 0,
                'max_tokens'  => 400,
            ]);

            $content = $response->choices[0]->message->content;

            // Иногда AI оборачивает в ```json ... ``` — убираем
            $content = preg_replace('/^```json\s*/i', '', $content);
            $content = preg_replace('/\s*```$/i', '', $content);

            $parsed = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('DataNormalizerService: невалидный JSON от AI', [
                    'content' => $content,
                    'input'   => $userMessage,
                ]);

                return null;
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('DataNormalizerService: ошибка вызова AI', [
                'error' => $e->getMessage(),
                'input' => $userMessage,
            ]);

            return null;
        }
    }
}
