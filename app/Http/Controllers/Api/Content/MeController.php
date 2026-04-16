<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Информация о текущем агенте.
 *
 * @tags Агент
 */
class MeController extends Controller
{
    /**
     * Получить информацию о текущем агенте.
     *
     * Возвращает данные пользователя, привязанного к Bearer-токену,
     * и список доступных API-эндпоинтов.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => [
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ],
                'endpoints' => [
                    'content_crud' => [
                        'news', 'articles', 'faqs', 'brand-stories',
                        'banners', 'pages', 'stories', 'promotions',
                        'product-selections',
                    ],
                    'catalog_read_only' => [
                        'products', 'brands', 'categories',
                    ],
                    'other' => ['tags', 'media', 'me'],
                ],
            ],
        ]);
    }
}
