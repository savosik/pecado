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
     * Подсказки по адресам.
     * POST /api/dadata/suggest/address
     */
    public function suggestAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:300'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'locations' => ['nullable', 'array'],
            'locations.*' => ['array'],
        ], [
            'query.required' => 'Поисковый запрос обязателен.',
            'query.min' => 'Введите минимум 2 символа.',
            'query.max' => 'Запрос слишком длинный.',
        ]);

        try {
            $suggestions = $this->client->suggestAddress(
                $validated['query'],
                $validated['count'] ?? 10,
                $validated['locations'] ?? null,
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
     * Обратное геокодирование: ближайшие адреса по координатам браузера.
     * POST /api/dadata/geolocate/address
     */
    public function geolocateAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'radius_meters' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'lat.required' => 'Широта обязательна.',
            'lon.required' => 'Долгота обязательна.',
        ]);

        try {
            $suggestions = $this->client->geolocateAddress(
                (float) $validated['lat'],
                (float) $validated['lon'],
                $validated['count'] ?? 5,
                $validated['radius_meters'] ?? 100,
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
     * Подсказки по email (популярные домены, исправление опечаток).
     * POST /api/dadata/suggest/email
     *
     * Доступен без авторизации — нужен на странице регистрации.
     * Защищён throttle на IP в роутах.
     */
    public function suggestEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [
            'query.required' => 'Поисковый запрос обязателен.',
            'query.max' => 'Запрос слишком длинный.',
        ]);

        try {
            $suggestions = $this->client->suggestEmail(
                $validated['query'],
                $validated['count'] ?? 5,
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
