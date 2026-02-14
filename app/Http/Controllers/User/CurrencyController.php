<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * Переключить валюту пользователя.
     * POST /api/currency/switch (web-redirect)
     */
    public function switch(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|exists:currencies,code',
        ]);

        $currency = Currency::where('code', $request->code)->firstOrFail();

        $request->user()->update([
            'currency_id' => $currency->id,
        ]);

        return back();
    }
}

