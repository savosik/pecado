<?php

namespace App\Console\Commands;

use App\Models\PurchasingAgentToken;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Выпуск, список и отзыв токенов ИИ-агентов закупщиков.
 *
 *   php artisan purchasing:token issue "Агент закупщика" --user=42
 *   php artisan purchasing:token list
 *   php artisan purchasing:token revoke 5
 *
 * Экрана управления в админке нет (закупщик один) — токены живут через консоль,
 * как аналитические.
 */
class PurchasingToken extends Command
{
    protected $signature = 'purchasing:token
        {action : issue | list | revoke}
        {value? : имя токена (для issue) или id токена (для revoke)}
        {--user= : id закупщика, от имени которого будет работать агент (обязателен для issue)}';

    protected $description = 'Управление токенами ИИ-агентов закупщиков для работы с уценкой через /mcp/purchasing';

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
            return $this->abortWith('Укажите имя токена: purchasing:token issue "Имя" --user=ID');
        }

        $userId = (int) $this->option('user');

        // Владелец обязателен: у токена есть право записи, и запись без автора
        // недопустима — «кто назначил цену» должно быть в каждой партии.
        if ($userId <= 0) {
            return $this->abortWith('Укажите закупщика: --user=ID. Токен без владельца выпустить нельзя.');
        }

        $user = User::find($userId);

        if (! $user) {
            return $this->abortWith("Сотрудника с id {$userId} нет.");
        }

        if (! $user->can('defects.view')) {
            return $this->abortWith("У «{$user->name}» нет права «defects.view» — агенту нечего было бы делать его правами.");
        }

        $token = PurchasingAgentToken::issue($name, $userId);

        $this->info("Токен «{$name}» выпущен для {$user->name} (id {$token->id}).");
        $this->newLine();
        $this->line('  URL:   '.url('/mcp/purchasing'));
        $this->line('  Токен: '.$token->token);
        $this->newLine();
        $this->warn('Агент сможет назначать цены уценки и управлять публикацией от имени этого сотрудника. Передавать по защищённому каналу.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $tokens = PurchasingAgentToken::with('user:id,name')->orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->warn('Токенов пока нет. Выпустить: purchasing:token issue "Имя" --user=ID.');

            return self::SUCCESS;
        }

        // Значение токена не печатаем: список смотрят в том числе через плечо,
        // а сам секрет виден один раз при выпуске.
        $this->table(
            ['id', 'Название', 'Сотрудник', 'Активен', 'Последнее использование'],
            $tokens->map(fn (PurchasingAgentToken $token): array => [
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
        $token = PurchasingAgentToken::find($id);

        if (! $token) {
            return $this->abortWith("Токен с id {$id} не найден. Список: purchasing:token list.");
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
