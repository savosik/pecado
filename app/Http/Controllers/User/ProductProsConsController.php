<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductProsConsController extends Controller
{
    public function generate($slug)
    {
        $product = Product::where('slug', $slug)->first();
        if (! $product) {
            return response()->json(['pros_cons' => null]);
        }

        if (! empty($product->pros_cons)) {
            return response()->json(['pros_cons' => $product->pros_cons]);
        }

        try {
            $apiKey = env('OPENROUTER_API_KEY', '');
            if (empty($apiKey)) {
                return response()->json(['pros_cons' => null]);
            }

            $parameters = '';
            if (method_exists($product, 'parameters')) {
                $parameters = $product->parameters()->get()->map(function ($p) {
                    return $p->name.': '.$p->value;
                })->implode(', ');
            }

            $desc = mb_substr(strip_tags($product->description_html ?? $product->description ?? ''), 0, 800);
            $brandName = '';
            if ($product->brand) {
                $brandName = $product->brand->name;
            }

            $prompt = 'Ты эксперт-копирайтер магазина интимных товаров. На основании описания и характеристик товара (НЕ на основании отзывов) составь объективный список плюсов и минусов. ';
            $prompt .= 'Товар: '.$product->name.'. ';
            $prompt .= 'Бренд: '.$brandName.'. ';
            $prompt .= 'Описание: '.$desc.'. ';
            $prompt .= 'Характеристики: '.$parameters.'. ';
            $prompt .= 'Правила: Плюсы 3-5, основанные ТОЛЬКО на характеристиках. Минусы 1-3, мягкие и конструктивные. НЕ выдумывай факты. Пиши кратко. ';
            $prompt .= 'Верни ТОЛЬКО JSON: {"pros": ["..."], "cons": ["..."]}. Никакого Markdown, никаких блоков кода.';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => env('OPENROUTER_MODEL', 'google/gemini-2.0-flash-001'),
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if (! $response->successful()) {
                Log::error('OpenRouter API error', ['status' => $response->status(), 'body' => $response->body()]);

                return response()->json(['pros_cons' => null]);
            }

            $body = $response->json();
            $text = $body['choices'][0]['message']['content'] ?? '';
            $clean = preg_replace('/^```json\s*|\s*```$/', '', trim($text));
            $data = json_decode($clean, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['pros']) && is_array($data['pros'])) {
                $prosCons = [
                    'pros' => array_slice($data['pros'], 0, 5),
                    'cons' => array_slice($data['cons'] ?? [], 0, 3),
                ];
                $product->update(['pros_cons' => json_encode($prosCons)]);

                return response()->json(['pros_cons' => $prosCons]);
            }

            return response()->json(['pros_cons' => null]);

        } catch (\Exception $e) {
            Log::error('Error generating pros_cons', ['slug' => $slug, 'error' => $e->getMessage()]);

            return response()->json(['pros_cons' => null]);
        }
    }
}
