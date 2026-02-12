<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * Переключить валюту пользователя.
     * POST /api/currency/switch
     */
    public function switch(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|exists:currencies,code',
        ]);

        $currency = Currency::where('code', $request->code)->firstOrFail();

        $request->user()->update([
            'currency_id' => $currency->id,
        ]);

        return response()->json([
            'message' => 'Валюта переключена',
            'currency' => [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
            ],
        ]);
    }
}
