<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клиент агентского CRM-API — тот же, которым ходит ИИ-агент менеджера.
 *
 * Нужен, чтобы вносить данные в боевую CRM с рабочей машины: доступа к серверу
 * по SSH нет, а запускать импорт «где-нибудь рядом с базой» — единственная
 * альтернатива — означало бы вообще ничего не внести.
 *
 * Токен превращается в сотрудника на стороне сервера, поэтому все права и вся
 * ответственность за записи — его: в ленте клиента правка будет выглядеть как
 * работа этого человека.
 */
class CrmApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Кто стоит за токеном: имя сотрудника и его права.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->get('me');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->send('GET', $uri, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $uri, array $payload = []): array
    {
        return $this->send('POST', $uri, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patch(string $uri, array $payload = []): array
    {
        return $this->send('PATCH', $uri, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, array $data): array
    {
        $response = $this->request()->send($method, $uri, [
            $method === 'GET' ? 'query' : 'json' => $data,
        ]);

        if ($response->failed()) {
            // Тело ответа в сообщении не роскошь: 422 от CRM объясняет, какое
            // поле не прошло проверку, и без него причина отказа теряется.
            throw new RuntimeException(sprintf(
                'CRM API %s %s → HTTP %d: %s',
                $method,
                $uri,
                $response->status(),
                mb_substr((string) $response->body(), 0, 500),
            ));
        }

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/crm/')
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            // Сеть до боевого сервера иногда моргает, а импорт идёт сотнями
            // запросов: без повтора один таймаут обрывал бы весь прогон.
            ->retry(3, 500, throw: false);
    }
}
