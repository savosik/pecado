<?php

namespace App\Console\Commands;

use App\Models\CrmAgentToken;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Выпуск, список и отзыв токенов ИИ-агентов CRM.
 *
 *   php artisan crm:token issue "Менеджер Сухов" --user=42
 *   php artisan crm:token list
 *   php artisan crm:token revoke 5
 *
 * Обычный путь выдачи — экран «Токены ИИ-агентов» в CRM: отзыв бывает срочным
 * (уволился сотрудник, утёк токен) и не должен ждать свободного разработчика.
 * Команда остаётся для случаев, когда экран недоступен.
 */
class CrmToken extends Command
{
    protected $signature = 'crm:token
        {action : issue | list | revoke}
        {value? : имя токена (для issue) или id токена (для revoke)}
        {--user= : id сотрудника, от имени которого будет работать агент (обязателен для issue)}';

    protected $description = 'Управление токенами ИИ-агентов менеджеров для пишущего доступа в CRM';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'issue' => $this->issue(),
            'list' => $this->list(),
            'revoke' => $this->revoke(),
            default => $this->abortWith('Неизвестное действие. Доступны: issue, list, revoke.'),
        };
    }

    private function issue(): int
    {
        $name = trim((string) $this->argument('value'));

        if ($name === '') {
            return $this->abortWith('Укажите имя токена: crm:token issue "Имя" --user=ID');
        }

        $userId = (int) $this->option('user');

        // Владелец обязателен: у токена есть право записи, и запись без автора
        // недопустима — «кто это сделал» должно быть в каждой строке.
        if ($userId <= 0) {
            return $this->abortWith('Укажите сотрудника: --user=ID. Токен без владельца выпустить нельзя.');
        }

        $user = User::find($userId);

        if (! $user) {
            return $this->abortWith("Сотрудника с id {$userId} нет.");
        }

        if (! $user->hasCrmAccess()) {
            return $this->abortWith("У «{$user->name}» нет доступа в CRM — агенту нечего было бы делать его правами.");
        }

        $token = CrmAgentToken::issue($name, $userId);

        $this->info("Токен «{$name}» выпущен для {$user->name} (id {$token->id}).");
        $this->newLine();
        $this->line('  URL:   '.url('/mcp/crm'));
        $this->line('  Токен: '.$token->token);
        $this->newLine();
        $this->warn('Агент сможет писать в CRM от имени этого сотрудника. Передавать по защищённому каналу.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $tokens = CrmAgentToken::with('user:id,name')->orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->warn('Токенов пока нет. Выпустить: crm:token issue "Имя" --user=ID.');

            return self::SUCCESS;
        }

        // Значение токена не печатаем: список смотрят в том числе через плечо,
        // а посмотреть сам секрет можно на экране под правом РОПа.
        $this->table(
            ['id', 'Название', 'Сотрудник', 'Активен', 'Последнее использование'],
            $tokens->map(fn (CrmAgentToken $token): array => [
                $token->id,
                $token->name,
                $token->user->name ?? '—',
                $token->is_active ? 'да' : 'отозван',
                $token->last_used_at?->diffForHumans() ?? 'ни разу',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $id = (int) $this->argument('value');
        $token = CrmAgentToken::find($id);

        if (! $token) {
            return $this->abortWith("Токен с id {$id} не найден. Список: crm:token list.");
        }

        $token->forceFill(['is_active' => false])->save();
        $this->info("Токен id {$id} («{$token->name}») отозван. Доступ закрыт немедленно.");

        return self::SUCCESS;
    }

    private function abortWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
