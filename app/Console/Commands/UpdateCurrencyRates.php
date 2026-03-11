<?php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Команда обновления курсов валют из ЦБ РФ.
 *
 * Используется только для ручного обновления через кнопку в AdminPanel.
 * По расписанию НЕ запускается (курсы в prod приходят из 1С через RabbitMQ, US-04).
 */
class UpdateCurrencyRates extends Command
{
    protected $signature = 'currency:update';

    protected $description = 'Обновить курсы валют из ЦБ РФ (только для ручного тестирования)';

    public function handle(): int
    {
        $this->info('Запрос курсов валют из ЦБ РФ...');

        try {
            $response = Http::timeout(10)->get('https://www.cbr.ru/scripts/XML_daily.asp');

            if ($response->failed()) {
                $this->error('Не удалось получить данные от ЦБ РФ.');
                Log::error('Currency update failed: Could not fetch data from CBR.');
                return Command::FAILURE;
            }

            // ЦБ РФ возвращает XML в windows-1251 — конвертируем в UTF-8
            $body = mb_convert_encoding($response->body(), 'UTF-8', 'Windows-1251');

            $xml = simplexml_load_string($body);
            if ($xml === false) {
                $this->error('Не удалось разобрать XML-ответ ЦБ РФ.');
                Log::error('Currency update failed: Invalid XML.');
                return Command::FAILURE;
            }

            $currencies = Currency::where('is_base', false)->get();
            $updatedCount = 0;

            foreach ($currencies as $currency) {
                $rateNode = null;
                foreach ($xml->Valute as $valute) {
                    if ((string) $valute->CharCode === $currency->code) {
                        $rateNode = $valute;
                        break;
                    }
                }

                if ($rateNode) {
                    $valueStr = str_replace(',', '.', (string) $rateNode->Value);
                    $nominal  = (float) $rateNode->Nominal;
                    $value    = (float) $valueStr;

                    if ($nominal > 0) {
                        // official_rate = курс ЦБ РФ за 1 единицу иностранной валюты в RUB
                        $officialRate = $value / $nominal;

                        // Применяем rate_coefficient из БД (был установлен из 1С или по умолчанию 1.0)
                        $rateCoefficient = (float) ($currency->rate_coefficient ?: 1.0);

                        // exchange_rate = итоговый курс = official_rate × rate_coefficient (по US-04)
                        $exchangeRate = $officialRate * $rateCoefficient;

                        $currency->update([
                            'official_rate'      => $officialRate,
                            'exchange_rate'      => $exchangeRate,
                            'exchange_rate_date' => now(),
                        ]);

                        $this->info("✓ {$currency->code}: official={$officialRate}, factor={$rateCoefficient}, exchange={$exchangeRate} RUB");
                        $updatedCount++;
                    }
                } else {
                    $this->warn("✗ Курс для {$currency->code} не найден в данных ЦБ РФ.");
                }
            }

            $this->info("Обновлено валют: {$updatedCount}.");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            Log::error('Currency update exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
