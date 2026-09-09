<?php

namespace App\Services\Crm\Avatars;

use App\Models\CrmClientAvatar;
use App\Models\User;
use App\Support\OpenRouter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Рисование аватарки партнёра в два хода через OpenRouter.
 *
 * Ход первый — текстовая модель превращает сухие факты («организация, крупный,
 * Москва, опт») в короткий английский промт для художника. Ход второй — модель
 * с картиночной модальностью рисует по этому промту.
 *
 * Почему не одним ходом: художник берёт промт буквально и по строке
 * «ООО Ромашка, оборот 4 млн» рисует табличку с текстом. Придумывать образ —
 * работа текстовой модели, и заодно промт остаётся в базе: через месяц видно,
 * почему у партнёра именно этот пингвин.
 *
 * Тон задан жёстко в системном сообщении: дружелюбно, без карикатуры на
 * внешность, возраст и национальность. Аватарку однажды увидят через плечо
 * менеджера, и она не должна оказаться поводом для разговора с юристом.
 */
class ClientAvatarGenerator
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    private const PROMPT_SYSTEM = <<<'TXT'
    Ты придумываешь ЗАБАВНЫЕ и ДОБРЫЕ аватарки для внутренней CRM оптового
    магазина. Аватарку видит только менеджер отдела продаж — она нужна, чтобы
    он узнавал партнёра в списке по картинке.

    По фактам о партнёре придумай промт для генератора изображений.

    Правила:
    - Отвечай ОДНОЙ строкой на английском, 25-45 слов, без кавычек и пояснений.
    - Это ПОРТРЕТ-МАСКОТ в квадрате: одно существо крупным планом, лицом к нам.
    - Никаких надписей, букв, цифр, логотипов и вывесок на картинке.
    - Никакой эротики, обнажёнки, оружия, алкоголя, религиозных символов.
    - Не шути про внешность, возраст, вес, национальность и деньги партнёра.
      Юмор — в характере и роде занятий: «сова-бухгалтер в очках», «кот-оптовик
      с тележкой», «лис-селлер с коробками».
    - Пол используй, только если он известен, и только для человекоподобного
      образа; если пол неизвестен — рисуй животное или предмет.
    - Масштаб дела передавай размахом сцены, а не суммами: у крупного партнёра
      величественная поза и богатый фон, у маленького — уютный и камерный.
    - Стиль: friendly flat vector mascot, bold clean shapes, soft studio light,
      simple solid background, centered square composition.
    TXT;

    public function __construct(private readonly PartnerFacts $facts) {}

    /**
     * @return array{binary: string, prompt: string, text_model: string, image_model: string}
     *
     * @throws RuntimeException любая осечка модели или сети — джоб превращает
     *                          её в счётчик попыток, а не в падение
     */
    public function generate(User $client): array
    {
        $apiKey = (string) config('normalizer.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Не задан OPENROUTER_API_KEY.');
        }

        $textModel = (string) config('crm_avatars.generation.text_model');
        $imageModel = (string) config('crm_avatars.generation.image_model');

        $prompt = $this->buildPrompt($client, $apiKey, $textModel);
        $binary = $this->draw($prompt, $apiKey, $imageModel);

        return [
            'binary' => $binary,
            'prompt' => $prompt,
            'text_model' => $textModel,
            'image_model' => $imageModel,
        ];
    }

    /**
     * Ход первый: факты → промт.
     */
    private function buildPrompt(User $client, string $apiKey, string $model): string
    {
        $facts = $this->facts->for($client);

        $response = $this->post($apiKey, [
            'model' => $model,
            'temperature' => 0.9,
            'max_tokens' => 300,
            'messages' => [
                ['role' => 'system', 'content' => self::PROMPT_SYSTEM],
                ['role' => 'user', 'content' => $this->factsAsText($facts)],
            ],
        ]);

        $prompt = trim((string) data_get($response, 'choices.0.message.content', ''));

        if ($prompt === '') {
            throw new RuntimeException('Текстовая модель не вернула промт.');
        }

        // Модель иногда оборачивает ответ в кавычки или предваряет «Prompt:» —
        // художнику это уедет как часть задания.
        $prompt = trim(preg_replace('/^(prompt|промт)\s*[:—-]\s*/iu', '', $prompt) ?? $prompt);

        return Str::limit(trim($prompt, " \t\n\r\0\x0B\"'«»"), 600, '');
    }

    /**
     * Ход второй: промт → картинка.
     */
    private function draw(string $prompt, string $apiKey, string $model): string
    {
        $response = $this->post($apiKey, [
            'model' => $model,
            // Часть моделей рисует, только когда картинка явно заказана
            // в модальностях; остальные поле игнорируют.
            'modalities' => ['image', 'text'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        return $this->extractImage($response);
    }

    /**
     * Картинка в ответе OpenRouter приходит по-разному в зависимости от модели:
     * отдельным полем `images`, вложением в контенте или data-URL прямо в тексте.
     * Разбираем все три, иначе смена модели в конфиге ломает генерацию.
     *
     * @param  array<string, mixed>  $response
     */
    private function extractImage(array $response): string
    {
        $candidates = [
            data_get($response, 'choices.0.message.images.0.image_url.url'),
            data_get($response, 'choices.0.message.images.0.url'),
        ];

        $content = data_get($response, 'choices.0.message.content');

        if (is_array($content)) {
            foreach ($content as $part) {
                $candidates[] = data_get($part, 'image_url.url');
            }
        }

        if (is_string($content)) {
            $candidates[] = $content;
        }

        foreach (array_filter($candidates) as $candidate) {
            $binary = $this->decodeDataUrl((string) $candidate);

            if ($binary !== null) {
                return $binary;
            }
        }

        throw new RuntimeException('Модель не вернула изображение.');
    }

    private function decodeDataUrl(string $value): ?string
    {
        if (! preg_match('#data:image/[a-z0-9.+-]+;base64,([A-Za-z0-9+/=\s]+)#i', $value, $matches)) {
            return null;
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);

        return $binary === false || $binary === '' ? null : $binary;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $apiKey, array $payload): array
    {
        $response = Http::withToken($apiKey)
            ->withHeaders([
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => (string) config('app.name'),
            ])
            ->withOptions(OpenRouter::httpOptions())
            ->timeout((int) config('crm_avatars.generation.request_timeout', 120))
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenRouter ответил '.$response->status().': '.Str::limit($response->body(), 200));
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        if (isset($data['error'])) {
            throw new RuntimeException('OpenRouter вернул ошибку: '.Str::limit(json_encode($data['error'], JSON_UNESCAPED_UNICODE) ?: '', 200));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function factsAsText(array $facts): string
    {
        $lines = [
            'Партнёр: '.$facts['name'],
            'Тип: '.$facts['kind'],
            'Пол: '.$facts['gender'],
            'Масштаб закупок за год: '.$facts['league'],
        ];

        if ($facts['city']) {
            $lines[] = 'Город: '.$facts['city'];
        }

        if ($facts['business_type']) {
            $lines[] = 'Чем занимается: '.$facts['business_type'];
        }

        if ($facts['years_with_us']) {
            $lines[] = 'Лет с нами: '.$facts['years_with_us'];
        }

        if ($facts['interests'] !== []) {
            $lines[] = 'Интересы: '.implode(', ', $facts['interests']);
        }

        return implode("\n", $lines);
    }

    /**
     * Пора ли пробовать снова: попытки не бесконечны, и после осечки берётся
     * пауза. Иначе очередь ходит к платному API по кругу за одним партнёром.
     */
    public function mayRetry(CrmClientAvatar $avatar): bool
    {
        if ($avatar->attempts < 1) {
            return true;
        }

        if ($avatar->attempts >= (int) config('crm_avatars.generation.max_attempts', 3)) {
            return false;
        }

        $cooldown = (int) config('crm_avatars.generation.failure_cooldown_hours', 24);

        return $avatar->failed_at === null || $avatar->failed_at->addHours($cooldown)->isPast();
    }
}
