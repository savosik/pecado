<?php

namespace App\Console\Commands;

use App\Models\AnalyticsToken as TokenModel;
use Illuminate\Console\Command;

/**
 * Выпуск, список и отзыв токенов доступа менеджеров к аналитическому MCP-серверу.
 *
 *   php artisan analytics:token issue "Иванова Мария"   # выпустить
 *   php artisan analytics:token list                    # показать все
 *   php artisan analytics:token revoke 5                # отозвать по id
 */
class AnalyticsToken extends Command
{
    protected $signature = 'analytics:token
        {action : issue | list | revoke}
        {value? : имя менеджера (для issue) или id токена (для revoke)}';

    protected $description = 'Управление токенами доступа менеджеров к аналитическому MCP';

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
        $name = (string) $this->argument('value');
        if (trim($name) === '') {
            return $this->abortWith('Укажите имя менеджера: analytics:token issue "Имя"');
        }

        $token = TokenModel::issue($name);

        $this->info("Токен для «{$name}» выпущен (id {$token->id}).");
        $this->newLine();
        $this->line('Отдайте менеджеру эти два значения — значение токена больше нигде не показывается:');
        $this->newLine();
        $this->line('  URL:   '.url('/mcp/analytics'));
        $this->line('  Токен: '.$token->token);
        $this->newLine();
        $this->warn('Токен даёт доступ к данным клиентов. Передавать по защищённому каналу, не в общий чат.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $tokens = TokenModel::orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->warn('Токенов пока нет. Выпустить: analytics:token issue "Имя".');

            return self::SUCCESS;
        }

        // Значение токена намеренно не печатаем: список могут смотреть через плечо,
        // а сам секрет виден только один раз при выпуске.
        $this->table(
            ['id', 'Менеджер', 'Активен', 'Последнее использование'],
            $tokens->map(fn ($t) => [
                $t->id,
                $t->name,
                $t->is_active ? 'да' : 'отозван',
                $t->last_used_at?->diffForHumans() ?? 'ни разу',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $id = (int) $this->argument('value');
        $token = TokenModel::find($id);

        if (! $token) {
            return $this->abortWith("Токен с id {$id} не найден. Список: analytics:token list.");
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
