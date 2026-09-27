<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна запись журнала вызовов клиентского API v1 / MCP `/mcp/client`.
 *
 * Вид записи — {@see self::KIND_MCP_CONNECT} (агент подключился), {@see self::KIND_MCP_TOOL}
 * (вызов инструмента) или {@see self::KIND_REST} (запрос REST v1). Аргументов и
 * ответов здесь нет намеренно: журнал отвечает «кто, когда, что и с каким
 * исходом», а не «что именно заказал».
 *
 * @property int $id
 * @property string $kind
 * @property int $user_id
 * @property int|null $token_id
 * @property int|null $company_id
 * @property string|null $session_id
 * @property string|null $agent
 * @property string|null $tool
 * @property string|null $operation
 * @property bool $mutating
 * @property bool $ok
 * @property string|null $error_code
 * @property int $duration_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read User $user
 * @property-read ApiToken|null $token
 */
class ClientAgentCall extends Model
{
    use Prunable;

    public const KIND_MCP_CONNECT = 'mcp_connect';

    public const KIND_MCP_TOOL = 'mcp_tool';

    public const KIND_REST = 'rest';

    public const UPDATED_AT = null;

    protected $fillable = [
        'kind',
        'user_id',
        'token_id',
        'company_id',
        'session_id',
        'agent',
        'tool',
        'operation',
        'mutating',
        'ok',
        'error_code',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'mutating' => 'boolean',
            'ok' => 'boolean',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'token_id');
    }

    /**
     * Ретенция журнала — `client_api.usage_retention_days`, чистит `model:prune`.
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(
            max(1, (int) config('client_api.usage_retention_days', 365)),
        ));
    }
}
