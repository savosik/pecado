<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DaData\DaDataClient;
use App\Services\DaData\DaDataException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DaDataController extends Controller
{
    public function __construct(private readonly DaDataClient $client) {}

    /**
     * Подсказки по компаниям (по названию или по ИНН).
     * POST /api/dadata/suggest/party
     */
    public function suggestParty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [
            'query.required' => 'Поисковый запрос обязателен.',
            'query.min' => 'Введите минимум 2 символа.',
            'query.max' => 'Запрос слишком длинный.',
        ]);

        try {
            $suggestions = $this->client->suggestParty(
                $validated['query'],
                $validated['count'] ?? 10,
            );
        } catch (DaDataException $e) {
            report($e);

            return response()->json([
                'message' => 'Сервис подсказок временно недоступен.',
            ], 503);
        }

        return response()->json(['suggestions' => $suggestions]);
    }

    /**
     * Точное получение реквизитов компании по ИНН.
     * POST /api/dadata/findById/party
     */
    public function findPartyByInn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inn' => ['required', 'string', 'regex:/^\d{10}$|^\d{12}$/'],
            'kpp' => ['nullable', 'string', 'regex:/^\d{9}$/'],
        ], [
            'inn.required' => 'ИНН обязателен.',
            'inn.regex' => 'ИНН должен содержать 10 или 12 цифр.',
            'kpp.regex' => 'КПП должен содержать 9 цифр.',
        ]);

        try {
            $party = $this->client->findPartyByInn(
                $validated['inn'],
                $validated['kpp'] ?? null,
            );
        } catch (DaDataException $e) {
            report($e);

            return response()->json([
                'message' => 'Сервис подсказок временно недоступен.',
            ], 503);
        }

        return response()->json(['party' => $party]);
    }

    /**
     * Подсказки по банкам (поиск по названию, БИК или SWIFT).
     * POST /api/dadata/suggest/bank
     */
    public function suggestBank(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [
            'query.required' => 'Поисковый запрос обязателен.',
            'query.min' => 'Введите минимум 2 символа.',
            'query.max' => 'Запрос слишком длинный.',
        ]);

        try {
            $suggestions = $this->client->suggestBank(
                $validated['query'],
                $validated['count'] ?? 10,
            );
        } catch (DaDataException $e) {
            report($e);

            return response()->json([
                'message' => 'Сервис подсказок временно недоступен.',
            ], 503);
        }

        return response()->json(['suggestions' => $suggestions]);
    }

    /**
     * Точное получение реквизитов банка по БИК.
     * POST /api/dadata/findById/bank
     */
    public function findBankByBik(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bik' => ['required', 'string', 'regex:/^\d{9}$/'],
        ], [
            'bik.required' => 'БИК обязателен.',
            'bik.regex' => 'БИК должен содержать 9 цифр.',
        ]);

        try {
            $bank = $this->client->findBankByBik($validated['bik']);
        } catch (DaDataException $e) {
            report($e);

            return response()->json([
                'message' => 'Сервис подсказок временно недоступен.',
            ], 503);
        }

        return response()->json(['bank' => $bank]);
    }
}
