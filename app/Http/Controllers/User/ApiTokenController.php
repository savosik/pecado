<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ApiTokenController extends Controller
{
    /**
     * Страница управления API-ключами и документацией API v1.
     */
    public function index()
    {
        return Inertia::render('User/Cabinet/ApiTokens/Index', [
            'tokens' => $this->tokens()->map(fn (ApiToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'token' => $token->token,
                'base_url' => $token->base_url,
                'v1_base_url' => $token->v1_base_url,
                'is_active' => $token->is_active,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
            ]),
            'docs' => $this->docs(),
        ]);
    }

    /**
     * Подключение ИИ-агента к MCP-серверу: тот же ключ, что у API v1.
     */
    public function mcp()
    {
        $token = $this->tokens()->firstWhere('is_active', true);

        return Inertia::render('User/Cabinet/ApiTokens/Mcp', [
            'apiKey' => $token?->token,
            'docs' => $this->docs(),
        ]);
    }

    /**
     * Описание прежнего API с ключом в адресе — только адреса ключей и методы.
     */
    public function legacy()
    {
        return Inertia::render('User/Cabinet/ApiTokens/Legacy', [
            'tokens' => $this->tokens()->map(fn (ApiToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'base_url' => $token->base_url,
            ]),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, ApiToken> */
    private function tokens()
    {
        return ApiToken::where('user_id', Auth::id())->latest()->get();
    }

    /** @return array<string, string> */
    private function docs(): array
    {
        return [
            'ui' => url('/docs/client-api'),
            'openapi' => url('/docs/client-api.json'),
            'mcp' => url('/mcp/client'),
        ];
    }

    /**
     * Создать новый API-ключ.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
        ], [
            'name.max' => 'Название не должно превышать 255 символов',
        ]);

        $token = ApiToken::create([
            'user_id' => Auth::id(),
            'name' => $request->input('name', 'API-ключ'),
        ]);

        return response()->json([
            'id' => $token->id,
            'name' => $token->name,
            'token' => $token->token,
            'base_url' => $token->base_url,
            'v1_base_url' => $token->v1_base_url,
            'is_active' => $token->is_active,
            'last_used_at' => null,
            'created_at' => $token->created_at?->toISOString(),
        ]);
    }

    /**
     * Перегенерировать хеш токена.
     */
    public function regenerate(ApiToken $apiToken): JsonResponse
    {
        abort_if($apiToken->user_id !== Auth::id(), 403, 'Доступ запрещён.');

        $apiToken->regenerateToken();

        return response()->json([
            'id' => $apiToken->id,
            'token' => $apiToken->token,
            'base_url' => $apiToken->base_url,
        ]);
    }

    /**
     * Удалить API-ключ.
     */
    public function destroy(ApiToken $apiToken): JsonResponse
    {
        abort_if($apiToken->user_id !== Auth::id(), 403, 'Доступ запрещён.');

        $apiToken->delete();

        return response()->json(['message' => 'API-ключ удалён']);
    }
}
