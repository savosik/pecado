<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DataNormalizerService
{
    /**
     * Нормализовать данные пользователя (ФИО + телефон + email) через OpenRouter AI.
     *
     * @return array{
     *   type: string,
     *   surname: ?string,
     *   name: ?string,
     *   patronymic: ?string,
     *   city: ?string,
     *   org_type: ?string,
     *   org_name: ?string,
     *   primary_phone: ?string,
     *   email: ?string,
     *   extra_info: ?string,
     * }|null
     */
    public function normalizeUser(
        ?string $surname,
        ?string $name,
        ?string $patronymic,
        ?string $phone = null,
        ?string $email = null,
    ): ?array {
        $systemPrompt = <<<'PROMPT'
Ты — парсер данных контрагентов из 1С. Разбери данные на структурированные поля.

ПРАВИЛА:
1. Если в ФИО записано название организации (ООО, ИП, ЗАО, ОАО, ТОО, АО, Отель и т.п.) — определи type="organization", собери полное название в org_name, очисти поля ФИО (установи null)
2. Если ИП — surname/name/patronymic должны быть ТОЛЬКО ФИО человека (без "ИП"). "ИП" → org_type
3. Если в отчестве есть город (г., обл., район) — вынеси город в city, отчество оставь чистым
4. Телефон: извлеки первый номер в формат +7XXXXXXXXXX (10 цифр после +7). Дополнительные номера, факсы, имена контактных лиц → добавь в extra_info
5. Если email не является валидным (нет @, это число и т.п.) — установи email=null, исходное значение добавь в extra_info
6. Если данные уже чистые — верни их как есть, type="person"
7. Все лишние данные (доп. телефоны, факсы, комментарии, форма собственности, город если есть) собирай в extra_info через "; "

ВЕРНИ ТОЛЬКО JSON (без markdown, без ```, без пояснений):
{
  "type": "person" или "organization",
  "surname": "string или null",
  "name": "string или null",
  "patronymic": "string или null",
  "city": "string или null",
  "org_type": "ИП" или "ООО" или "ЗАО" или "ОАО" или "ТОО" или "АО" или null,
  "org_name": "string или null",
  "primary_phone": "string в формате +7XXXXXXXXXX или null",
  "email": "string или null",
  "extra_info": "string или null"
}
PROMPT;

        $userMessage = "surname: \"{$surname}\"\nname: \"{$name}\"\npatronymic: \"{$patronymic}\"";

        if ($phone) {
            $userMessage .= "\nphone: \"{$phone}\"";
        }

        if ($email) {
            $userMessage .= "\nemail: \"{$email}\"";
        }

        return $this->callAi($systemPrompt, $userMessage);
    }

    /**
     * Нормализовать данные компании (телефон + email) через OpenRouter AI.
     *
     * @return array{
     *   primary_phone: ?string,
     *   email: ?string,
     *   extra_info: ?string,
     * }|null
     */
    public function normalizeCompany(
        ?string $phone = null,
        ?string $email = null,
    ): ?array {
        $systemPrompt = <<<'PROMPT'
Ты — парсер контактных данных компаний из 1С.

ПРАВИЛА:
1. Телефон: извлеки первый номер в формат +7XXXXXXXXXX. Дополнительные номера, факсы, имена → extra_info
2. Если email не валидный — email=null, значение → extra_info
3. Чистые данные — верни как есть

ВЕРНИ ТОЛЬКО JSON:
{
  "primary_phone": "string в формате +7XXXXXXXXXX или null",
  "email": "string или null",
  "extra_info": "string или null"
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
                    'timeout' => config('normalizer.timeout', 10),
                ]))
                ->make();

            $response = $client->chat()->create([
                'model'       => config('normalizer.model', 'openai/gpt-4o-mini'),
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'temperature' => 0,
                'max_tokens'  => 300,
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
